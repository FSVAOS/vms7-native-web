<?php

namespace Modules\SmartCARSNative\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Acars;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\Subfleet;
use App\Models\User;
use App\Services\BidService;
use App\Services\FlightService;
use App\Services\PirepService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * class ApiController
 * @package Modules\SmartCARSNative\Http\Controllers\Api
 */
class FlightsController extends Controller
{
    public function __construct(public FlightService $flightService, public BidService $bidService, public PirepService $pirepService)
    {
    }

    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function book(Request $request)
    {
        $flight = Flight::find($request->input('flightID'));
        $user = User::find($request->get('pilotID'));
        $bid = $this->bidService->addBid($flight, $user);
        return response()->json(["bidID" => $bid->id]);
    }
    public function bookings(Request $request)
    {
        $bids = $this->bidService->findBidsForUser(User::find($request->get('pilotID')));
        $output = [];

        foreach ($bids as $bid) {
            // Aircraft Array
            $aircraft = [];
            foreach ($bid->flight->subfleets as $subfleet) {
                foreach ($subfleet->aircraft as $acf) {
                    $aircraft[] = $acf['id'];
                }
            }

            $output[] = [
                "bidID" => $bid->id,
                "number" => $bid->flight->flight_number,
                "code" => $bid->flight->airline->code,
                "departureAirport" => $bid->flight->dpt_airport_id,
                "arrivalAirport" => $bid->flight->arr_airport_id,
                "route" => [],
                "flightLevel" => $bid->flight->level,
                "distance" => $bid->flight->distance,
                "departureTime" => $bid->flight->dpt_time,
                "arrivalTime" => $bid->flight->arr_time,
                "flightTime" => $bid->flight->flight_time,
                "daysOfWeek" => $bid->flight->days,
                "flightID" => $bid->flight->id,
                "type" => $bid->flight->flight_type,
                "aircraft" => $aircraft
            ];
        }

        return response()->json($output);
    }
    public function charter(Request $request)
    {
        $attrs = [
            'flight_number' => $request->flight_number,
            'airline_id' => $request->airline_id,
            'dpt_airport_id' => $request->dpt_airport_id,
            'arr_airport_id' => $request->arr_airport_id,
            'aircraft_id' => $request->input('aircraftID'),
            'flight_id' => $request->id,
            'source' => PirepSource::ACARS,
            'source_name' => "smartCARS 3"
        ];
        // Check if the pirep already exists.
        $existing = Pirep::where(['user_id' => $request->id, 'state' => PirepState::IN_PROGRESS])->first();
        if (is_null($existing)) {
            try {
                $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            } catch (\Exception $e) {
                logger($e);
                return response()->json(['message' => $e->getMessage()], 500);
            }
            return response()->json($pirep);
        }
        return response()->json($existing);
    }
    public function complete(Request $request)
    {
        $input = $request->all();
        logger($input);
        //dd($request);
        $pirep = Pirep::find($input['bidID']);
        Log::info("Found Pirep to close out");
        $pirep->status = PirepStatus::ARRIVED;
        $pirep->state = PirepState::PENDING;
        $pirep->source = PirepSource::ACARS;
        $pirep->source_name = "smartCARS 3";
        $pirep->landing_rate = $input['landingRate'];
        $pirep->fuel_used = $input['fuelUsed'];
        $pirep->flight_time = $input['flightTime']  * 60;

        if (gettype($input['flightLog']) === "string") {
            $input['flightLog'] = base64_decode($input['flightLog']);
            $input['flightLog'] = explode("\n", $input['flightLog']);
            logger($input['flightLog']);
        }
        if (gettype($input['flightData']) === "string") {
            $input['flightData'] = base64_decode($input['flightData']);
            $input['flightData'] = json_decode($input['flightData'], true);
            logger($input['flightData']);
        }

        foreach ($input['flightData'] as $data) {
            $log_item = new Acars();
            $log_item->type = AcarsType::LOG;
            $log_item->log = $data['message'];
            $log_item->created_at = Carbon::createFromTimeString($data['eventTimestamp']);
            $pirep->acars_logs()->save($log_item);
        }
        if (!is_null($input['comments']))
        {
            foreach ($input['flightLog'] as $comment) {
                if (str_contains($comment, " Comment:"))
                {
                    $pirep->comments()->create([
                        'user_id' => Auth::user()->id,
                        'comment' => $comment
                    ]);
                }
            }
        }

        $pirep->save();
        $this->pirepService->submit($pirep);

        return response()->json(['pirepID' => $pirep->id]);


    }
    public function search(Request $request)
    {
        $output = [];

        $query = [];
        $subfleet = null;
        if ($request->has('departureAirport') && $request->query('departureAirport') !== null) {
            $apt = Airport::where('icao', $request->query('departureAirport'))->first();
            if (!is_null($apt))
                $query['dpt_airport_id'] = $apt->id;
        }

        if ($request->has('arrivalAirport') && $request->query('arrivalAirport') !== null) {
            $apt = Airport::where('icao', $request->query('arrivalAirport'))->first();
            if (!is_null($apt))
                $query['arr_airport_id'] = $apt->id;
        }
        if ($request->has('aircraft') && $request->query('aircraft') !== null) {
            // Yank the subfleet by ID
            $apt = Subfleet::find($request->query('aircraft'));
            if (!is_null($apt))
                $subfleet = $apt->id;
        }
        if (!empty($subfleet))
        {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet]);
                })->get()->take(100);
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet]);
                })->get()->take(100);
            }
        } else {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->get()->take(100);
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->get()->take(100);
            }
        }

        foreach ($flights as $bid) {
            $aircraft = [];
            //dd($bid);
            if (is_null($bid->subfleets))
                continue;
            foreach ($bid->subfleets as $subfleet) {
                $aircraft[] = $subfleet->type;
            }
            $output[] = [
                "id" => $bid->id,
                "number" => $bid->flight_number,
                "code" => $bid->airline->code,
                "departureAirport" => $bid->dpt_airport_id,
                "arrivalAirport" => $bid->arr_airport_id,
                "flightLevel" => $bid->level,
                "distance" => $bid->distance,
                "departureTime" => $bid->dpt_time,
                "arrivalTime" => $bid->arr_time,
                "flightTime" => $bid->flight_time,
                "daysOfWeek" => [],
                "type" => $bid->flight_type,
                "subfleets" => $aircraft
            ];
        }

        return response()->json($output);
    }
    public function prefile(Request $request)
    {
        $user = Auth::user();
        $bid = Bid::find($request->input('bidID'));
        logger($request->all());
        $flight = Flight::find($bid->flight_id);

        $attrs = [
            'flight_number' => $flight->flight_number,
            'airline_id' => $flight->airline_id,
            'dpt_airport_id' => $flight->dpt_airport_id,
            'arr_airport_id' => $flight->arr_airport_id,
            'aircraft_id' => $request->input('aircraftID'),
            'flight_id' => $flight->id,
            'source' => PirepSource::ACARS,
            'source_name' => "smartCARS 3"
        ];
        // Check if the pirep already exists.
        $existing = Pirep::where(['user_id' => $user->id, 'state' => PirepState::IN_PROGRESS])->first();
        if (is_null($existing)) {
            try {
                $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            } catch (\Exception $e) {
                logger($e);
                return response()->json(['message' => $e->getMessage()], 500);
            }
            return response()->json($pirep);
        }
        return response()->json($existing);

    }
    public function unbook(Request $request)
    {
        Bid::where(['user_id' => $request->get('pilotID'), 'id' => $request->post('bidID')])->delete();
    }
    public function update(Request $request)
    {
        try {
            $input = $request->all();
            $pirep = Pirep::find($input['bidID']);

            $pirep->status = $this->phaseToStatus($input['phase']);
            $pirep->save();
            $pirep->acars()->create([
                'status' => $this->phaseToStatus($input['phase']),
                'type' => AcarsType::FLIGHT_PATH,
                'lat' => $input['latitude'],
                'lon' => $input['longitude'],
                'distance' => $input['distanceRemaining'],
                'heading' => $input['heading'],
                'altitude' => $input['altitude'],
                'gs' => $input['groundSpeed']
            ]);
        }catch (\Exception $e)
        {
            logger($e->getTrace());

        }
    }

    function phaseToStatus(string $phase) {
        switch(strtolower($phase)) {
            case 'boarding':
                return PirepStatus::BOARDING;
            case 'push_back':
                return PirepStatus::PUSHBACK_TOW;
            case 'taxi':
                return PirepStatus::TAXI;
            case 'take_off':
                return PirepStatus::TAKEOFF;
            case 'rejected_take_off':
                return PirepStatus::TAXI;
            case 'climb_out':
                return PirepStatus::INIT_CLIM;
            case 'climb':
                return PirepStatus::ENROUTE;
            case 'cruise':
                return PirepStatus::ENROUTE;
            case 'descent':
                return PirepStatus::APPROACH;
            case 'approach':
                return PirepStatus::APPROACH_ICAO;
            case 'final':
                return PirepStatus::LANDING;
            case 'landed':
                return PirepStatus::LANDED;
            case 'go_around':
                return PirepStatus::APPROACH;
            case 'taxi_to_gate':
                return PirepStatus::LANDED;
            case 'deboarding':
                return PirepStatus::ARRIVED;
            case 'diverted':
                return PirepStatus::DIVERTED;
            default:
                return null;
        }
    }


}
