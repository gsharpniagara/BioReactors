<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App;
use Lang;

use App\Bioreactor;
use App\Temperature;
use App\Lightreading;
use App\Gasflow;
use App\Phreading;

use Carbon\Carbon;
// use AppNamespaceDetectorTrait;

use DB;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

  /**  @var Bioreactor $bioreactor      read in via getBioreactorFromId */
  /**  @var Temperature Collection $temperatures   read in via getTemperatureData */
  /**  @var Temperature [] $x_temperatures   JS Charts data created in _buildXYTemperatureData */
  /**  @var Temperature [] $y_temperatures   JS Charts data created in _buildXYTemperatureData */

  protected $bioreactor="";

  protected $temperatures;
  protected $x_temperatures=array();
  protected $y_temperatures=array();

  protected $lightreadings;
  protected $x_lightreadings=array();
  protected $y_lightreadings=array();

  protected $gasflows;
  protected $x_gasflows=array();
  protected $y_gasflows=array();

  protected $phreadings;
  protected $x_phreadings=array();
  protected $y_phreadings=array();

  // type: reference string used to identify the [code for] sensor
  // prop: propery used to hold measurement data loaded from database
  // name: abbreviated sensor type
  // route: «?temporary?» router for the full graph view
  // view: The view (folder) for sensor specific [partial] blades
  // data_field: database field name holding measurment ValidatesRequests
  // measure_fmt: sprintf format used to create graph data points
  protected $sensors = [
    'gasflows'       => [
      'type'        => 'gasflow',
      'prop'        => 'gasflows',
      'name'        => 'flow',
      'view'        => 'GasFlows',
      'data_field'  => 'flow',
      'model'       => 'Gasflow',
      'measure_fmt' => "%5.2f",
    ],
    'lightreadings' => [
      'type'        => 'lightreading',
      'prop'        => 'lightreadings',
      'name'        => 'light',
      'view'        => 'LightReadings',
      'data_field'  => 'lux',
      'model'       => 'Lightreading',
      'measure_fmt' => "%6.1f",
    ],
    'phreadings' => [
      'type'        => 'phreading',
      'prop'        => 'phreadings',
      'name'        => 'ph',
      'view'        => 'PhReadings',
      'data_field'  => 'lux',
      'model'       => 'Phreading',
      'measure_fmt' => "%6.1f",
    ],
    'temperatures'   => [
      'type'        => 'temperature',
      'prop'        => 'temperatures',
      'name'        => 'temp',
      'view'        => 'Temperatures',
      'data_field'  => 'temperature',
      'model'       => 'Temperature',
      'measure_fmt' => "%02.2f",
    ],
  ];

  /**
   * Read the Bioreactor record from the table based on the deviceid
   * parameter. The record is stored in the class as well as being
   * returned
   *
   * @param string $id The deviceid ex. '00002'
   *
   * @throws Exception if no record exists. Not supposed to happen.
   *
   * @return Bioreactor
   */
  public function getBioreactorFromId( $id )
  {

    // correct id from uri if in the wrong format (or missing)!!
    $id = Bioreactor::formatDeviceid( $id );

    // load the record from the table
    try {
      $this->bioreactor = Bioreactor::where('deviceid', '=', $id)->firstOrFail();
    }
    catch (\Exception $e) {
      $message = Lang::get('export.invalid_deviceid');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }
    //dd($bioreactor);

    return $this->bioreactor;
  }

  /**
   * Read the temperature measurement records from the table for a specific
   * deviceid parameter. The records are summarized by the hour
   *
   * @param string $id The deviceid ex. '00002'
   * @param Carbon $start_time date and time to read records after
   * @param Carbon $last_time date and time of most recent record
   *
   * @return null
   */
  protected function _getHourlySummaryTemperatureData( $deviceid, $start_time, $last_time )
  {
    // using raw call to the DB. I can't see how to do it using Eloquent
    // so going back to basics.
    // truncates all the recorded_on details down to just the hour.
    // In other words we are summarizing the results down to the average
    // over the hour.
    $r=DB::table('temperatures')
      ->select('deviceid', 'recorded_on',
      DB::raw('strftime("%H",time(recorded_on)) as hrs'),
      DB::raw('sum(temperature)/count(*) as temperature'))
      ->groupBy('hrs')
      ->where('deviceid', '=', $deviceid)
      ->where('recorded_on', '>', $start_time->toDateTimeString() )
      ->get();

    // make a 24 element array to hold the x data points
    // The results of the above table get() may be missing data so it
    // may not return 24 results. we need to put zero in first
    $all_day=[];
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    // the all_day array is an array of arrays. this is the format that we can use
    // to backfill the results into the eloquent format using the hydrate call
    for ( $i=0; $i < 24; $i++) {
      // for each hour, make an array holding the results
      $row = ['deviceid'=>$deviceid, 'hrs'=> 0, 'temperature'=>'0.0', 'recorded_on'=>$hr_time->toDateTimeString()];
      $all_day[$i] = $row;
      $hr_time->subhours(1);
    }

    // overwrite the average temperatures with the data from the actual
    // table get. Note we are putting the order to be the most recent hour last.
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    for ( $i=0; $i < sizeof($r); $i++) {
      $trec = new Carbon($r[$i]->recorded_on);
      $trec->minute=0;
      $trec->second=0;
      $index = $hr_time->diffInHours($trec);

      $all_day[$index]['temperature'] =
        sprintf("%02.1f",$r[$i]->temperature);
    }

    // the hydrate function will put our constructed array into the
    // Collection format that we need
    $this->temperatures = Temperature::hydrate($all_day);
    //dd($this->temperatures);
  }

  /**
   * Read the temperature measurement records from the table for a specific deviceid
   * parameter. The records are stored in the class, loaded in descending order
   * by dateTime.  In other words the most recent first.
   * The date the most recent record was recorded is returned.
   *
   * @param string $id The deviceid ex. '00002'
   * @param int $data_size = 3  Number of hours of data (3 or 24)
   *
   * @throws Exception if SQL select fails (no records is ok though)
   *
   * @return Carbon datetime of last record
   */
  public function getTemperatureData( $id, $data_size=3 )
  {
    $deviceid = Bioreactor::formatDeviceid($id); // format to 00000

    // get the last data entry record. Use the record_on time
    // and go backwards from that time to retrieve records
    try {
      $most_recent_measurement = Temperature::where('deviceid', '=', $deviceid)->orderBy('recorded_on', 'desc')->first();
      if ( is_null($most_recent_measurement)) {
        App::abort(404);
      }
    }
    catch (\Exception $e) {
      $start_time = Carbon::now();
      return $start_time;
    }
    $last_time = new Carbon($most_recent_measurement->recorded_on);

    // subtract # of hours. We need to use a new Carbon or it will
    // just point at the old one anyways!
    $start_time = new Carbon($last_time);
    $start_time->subHours($data_size);

    // load the measurement data for this site
    try {
      if ($data_size==24) {
        $this->_getHourlySummaryTemperatureData($deviceid,$start_time,$last_time);
      }
      else {
        $this->temperatures = Temperature::where('deviceid', '=', $deviceid)->where('recorded_on', '>', $start_time->toDateTimeString() )->orderBy('recorded_on', 'desc')->get();
      }
    }
    catch (\Exception $e) {
      $message = Lang::get('export.no_temperature_data_found');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }

    //dd($this->temperatures);
    return $last_time;
  }


  /**
   * Read the sensor measurement records from the table for a specific deviceid
   * parameter. The records are stored in the class, loaded in descending order
   * by dateTime.  In other words the most recent first.
   * The date the most recent record was recorded is returned.
   *
   * @param string $sensor Key to $sensor table of (sensor specific) properties
   * @param string $id The deviceid ex. '00002'
   * @param int $data_size = 3  Number of hours of data (3 or 24)
   *
   * @throws Exception if SQL select fails (no records is ok though)
   *
   * @return Carbon datetime of last record
   */
  public function getSensorData( $sensor, $id, $data_size=3 )
  {
    $deviceid = Bioreactor::formatDeviceid($id); // format to 00000

    // get the last data entry record. Use the record_on time
    // and go backwards from that time to retrieve records
    $sensor_props = $this->sensors[ $sensor ];
    // $sensor_model =  $sensor_props[ 'model' ];
    $sensor_model =  'App\\' . $sensor_props[ 'model' ];
    // https://laracasts.com/discuss/channels/eloquent/access-eloquent-model-dynamically
    // https://laravel.com/docs/5.2/eloquent Dynamic Scope
    try {
      // Temperature::
      $most_recent_measurement = $sensor_model::where('deviceid', '=', $deviceid)->orderBy('recorded_on', 'desc')->first();
      if ( is_null($most_recent_measurement)) {
        App::abort(404);
      }
    }
    catch (\Exception $e) {
      $start_time = Carbon::now();
      return $start_time;
    }
    $last_time = new Carbon($most_recent_measurement->recorded_on);

    // subtract # of hours. We need to use a new Carbon or it will
    // just point at the old one anyways!
    $start_time = new Carbon($last_time);
    $start_time->subHours($data_size);

    // load the measurement data for this site
    try {
      if ($data_size==24) {
        $this->_getHourlySummaryTemperatureData($deviceid,$start_time,$last_time);
      }
      else {
        $this->{ $sensor_props[ 'prop' ]} = $sensor_model::where('deviceid', '=', $deviceid)->where('recorded_on', '>', $start_time->toDateTimeString() )->orderBy('recorded_on', 'desc')->get();
      }
    }
    catch (\Exception $e) {
      $message = Lang::get('export.no_' . $sensor_props[ 'type' ] . '_data_found');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }

    //dd($this->{ $sensor_props[ 'prop' ]});
    return $last_time;
  }


  /**
   * Builds the x and y temperature graph arrays that are passed to the
   * javascript Chart builder. The temperature records must already
   * have been loaded into the temperatures Collection in this class
   *
   * @param string $x_axis_style ='default' 'default' is time. 'dot' is a dot
   *
   * @throws Exception if temperatures have not been loaded from table yet
   *
   * @return Array Mixed  x and y temperature chart data
   */
  public function _buildXYTemperatureData($x_axis_style='default')
  {
    // put the data in the correct form for the charts JS library
    // generate an x and y array
    // x holds time labels in hh:mm format
    // y holds temperatures as nn.nn format

    $this->x_temperatures = [];
    $this->y_temperatures = [];

    // if the temperatures have not been loaded
    // indicates that gettemperature data has not been called
    // or failed (no recs)
    if ( ! is_null ($this->temperatures) && count($this->temperatures)>0) {

      // reverse the order to make the graph more human like
      $rev_temps = $this->temperatures->reverse();

      foreach ($rev_temps as $temperature) {

         $dt = new carbon($temperature->recorded_on);

        switch($x_axis_style)
        {
        case 'dot':
          $this->x_temperatures[] = '.';
          break;
        default:
          $this->x_temperatures[] = $dt->format('h:i');
          break;

        }
        $this->y_temperatures[] = sprintf("%02.2f",$temperature->temperature);
      }
    }

    // just put something in if there is no data
    // otherwise no graph will be generated
    if ( is_null ($this->temperatures) || (count($this->temperatures) < 1) )
    {
      $this->x_temperatures[]='0';
      $this->y_temperatures[]=0;
    }

    //dd($this->x_temperatures);
    //dd($this->y_temperatures);

    return [
      'x_data' => $this->x_temperatures,
      'y_data' => $this->y_temperatures
    ];
  }

  /**
   * Create the x and y data points needed for the javascript chart builder
   * The measurement records must already have been loaded into the
   * sensor specific Collection in this class
   *
   * @throws Exception if measurements have not been loaded from table yet
   *
   * @return Array sensor measurement data points
   */
  public function _buildXYMeasurementData( $sensor_name )
  {
    $sensor_properties = $this -> sensors[ $sensor_name ];
    // dd( $sensor_properties );
    // prop, data_field, measurement_fmt
    $xy_data = [];

    // if the measurements have not been loaded, or failed (no records)
    if ( is_null( $this ->{ $sensor_properties[ 'prop' ] })||( count( $this ->{ $sensor_properties[ 'prop' ] }) < 1 ))
    {
      // fill something in, otherwise no graph will be generated
      $xy_data[] = [ 'x' => '0', 'y' => 0 ];
    } else {
      // reverse the order to make the graph more human like
      $rev_records = $this ->{ $sensor_properties[ 'prop' ] } -> reverse();
      foreach ( $rev_records as $reading ) {
        $dt = new carbon( $reading->recorded_on );
        $xy_data[] = [
          'x' => $dt -> timestamp,
          'y' => sprintf( $sensor_properties[ 'measure_fmt' ], $reading ->{ $sensor_properties[ 'data_field' ]})
        ];
      }
    }

    // return $this->{ $sensor_properties[ 'xy_prop' ]};
    return $xy_data;
  }


  /**
   * Read the light intensity measurement records from the table for a specific
   * deviceid parameter. The records are summarized by the hour
   *
   * @param string $id The deviceid ex. '00002'
   * @param Carbon $start_time date and time to read records after
   * @param Carbon $last_time date and time of most recent record
   *
   * @return null
   */
  protected function _getHourlySummaryLightreadingData( $deviceid, $start_time, $last_time )
  {
    // using raw call to the DB. I can't see how to do it using Eloquent
    // so going back to basics.
    // truncates all the recorded_on details down to just the hour.
    // In other words we are summarizing the results down to the average
    // over the hour.
    $r=DB::table('lightreadings')
      ->select('deviceid', 'recorded_on',
      DB::raw('strftime("%H",time(recorded_on)) as hrs'),
      DB::raw('sum(lux)/count(*) as lux'))
      ->groupBy('hrs')
      ->where('deviceid', '=', $deviceid)
      ->where('recorded_on', '>', $start_time->toDateTimeString() )
      ->get();

    // make a 24 element array to hold the x data points
    // The results of the above table get may be missing data so it
    // may not return 24 results. we need to put zero in first
    $all_day=[];
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    // the all_day array is an array of arrays. this is the format that we can use
    // to backfill the results into the eloquent format using the hydrate call
    for ( $i=0; $i < 24; $i++) {
      // for each hour, make an array holding the results
      $row = ['deviceid'=>$deviceid, 'hrs'=> 0, 'lux'=>'0.0', 'recorded_on'=>$hr_time->toDateTimeString()];
      $all_day[$i] = $row;
      $hr_time->subhours(1);
    }

    // overwrite the average lightreadings with the data from the actual
    // table get. Note we are putting the order to be the most recent hour last.
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    for ( $i=0; $i < sizeof($r); $i++) {
      $trec = new Carbon($r[$i]->recorded_on);
      $trec->minute=0;
      $trec->second=0;
      $index = $hr_time->diffInHours($trec);

      $all_day[$index]['lux'] =
        sprintf("%6.1f",$r[$i]->lux);
    }

    // the hydrate function will put our constructed array into the
    // Collection format that we need
    $this->lightreadings = Lightreading::hydrate($all_day);
    //dd($this->lightreadings);
  }


  /**
   * Read the Light intensity records from the table for a specific deviceid
   * parameter. The records are stored in the class, loaded in descending order
   * by dateTime.  In other words the most recent first.
   * The date the most recent record was recorded is returned.
   *
   * @param string $id The deviceid ex. '00002'
   * @param int $data_size = 3  Number of hours of data (3 or 24)
   *
   * @throws Exception if SQL select fails (no records is ok though)
   *
   * @return Carbon datetime of last record
   */
  public function getLightreadingData( $id, $data_size=3 )
  {
    $deviceid = Bioreactor::formatDeviceid($id); // format to 00000

    // get the last data entry record. Use the record_on time
    // and go backwards from that time to retrieve records
    try {
      $most_recent_measurement = Lightreading::where('deviceid', '=', $deviceid)->orderBy('recorded_on', 'desc')->first();
      if ( is_null($most_recent_measurement)) {
        App::abort(404);
      }
    }
    catch (\Exception $e) {
      $start_time = Carbon::now();
      return $start_time;
    }
    $last_time = new Carbon($most_recent_measurement->recorded_on);

    // subtract # of hours. We need to use a new Carbon or it will
    // just point at the old one anyways!
    $start_time = new Carbon($last_time);
    $start_time->subHours($data_size);

    // load the measurement data for this site
    try {
      if ( $data_size==24 ) {
        $this->_getHourlySummaryLightreadingData($deviceid,$start_time,$last_time);
      }
      else {
        $this->lightreadings = Lightreading::where('deviceid', '=', $deviceid)->where('recorded_on', '>', $start_time->toDateTimeString() )->orderBy('recorded_on', 'desc')->get();
      }
    }
    catch (\Exception $e) {
      $message = Lang::get('export.no_lightreading_data_found');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }

    //dd($this->lightreadings);
    return $last_time;
  }

  /**
   * Builds the x and y Lightreading graph arrays that are passed to the
   * javascript Chart builder. The Lightreading records must already
   * have been loaded into the Lightreading Collection in this class
   *
   * @param string $x_axis_style ='default' 'default' is time. 'dot' is a dot
   *
   * @throws Exception if Lightreading have not been loaded from table yet
   *
   * @return Array Mixed  x and y Lightreading chart data
   */
  public function _buildXYLightreadingData($x_axis_style='default')
  {

    // put the data in the correct form for the charts JS library
    // generate an x and y array
    // x holds time labels in hh:mm format
    // y holds y_lightreadings as nnnnn.n format

    $this->x_lightreadings = [];
    $this->y_lightreadings = [];

    // abort if the lightreadings have not been loaded
    // indicates that getlightreading data has not been called
    if ( ! is_null ($this->lightreadings) && count($this->lightreadings)>0) {

      // reverse the order to make the graph more human like
      $rev_light = $this->lightreadings->reverse();

      foreach ($rev_light as $lightreading) {

         $dt = new carbon($lightreading->recorded_on);

        switch($x_axis_style)
        {
          case 'dot':
            $this->x_lightreadings[] = '.';
            break;
          default:
            $this->x_lightreadings[] = $dt->format('h:i');
            break;
        }
        $this->y_lightreadings[] = sprintf("%6.1f",$lightreading->lux);
      }
    }

    // just put something in if there is no data
    // otherwise no graph will be generated
    if (is_null ($this->lightreadings) || (count($this->lightreadings) < 1) )
    {
      $this->x_lightreadings[]='0';
      $this->y_lightreadings[]=0;
    }

    //dd($this->x_lightreadings);
    //dd($this->y_lightreadings);

    return [
      'x_data' => $this->x_lightreadings,
      'y_data' => $this->y_lightreadings
    ];
  }

  /**
   * Read the gas flow measurement records from the table for a specific
   *deviceid parameter. The records are summarized by the hour
   *
   * @param string $id The deviceid ex. '00002'
   * @param Carbon $start_time date and time to read records after
   * @param Carbon $last_time date and time of most recent record
   *
   * @return null
   */
  protected function _getHourlySummaryGasflowData( $deviceid, $start_time, $last_time)
  {
    // using raw call to the DB. I can't see how to do it using Eloquent
    // so going back to basics.
    // truncates all the recorded_on details down to just the hour.
    // In other words we are summarizing the results down to the average
    // over the hour.
    $r=DB::table('gasflows')
      ->select('deviceid', 'recorded_on',
      DB::raw('strftime("%H",time(recorded_on)) as hrs'),
      DB::raw('sum(flow)/count(*) as flow'))
      ->groupBy('hrs')
      ->where('deviceid', '=', $deviceid)
      ->where('recorded_on', '>', $start_time->toDateTimeString() )
      ->get();

    // make a 24 element array to hold the x data points
    // The results of the above table get may be missing data so it
    // may not return 24 results. we need to put zero in first
    $all_day=[];
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    // the all_day array is an array of arrays. this is the format that we can use
    // to backfill the results into the eloquent format using the hydrate call
    for ( $i=0; $i < 24; $i++) {
      // for each hour, make an array holding the results
      $row = ['deviceid'=>$deviceid, 'hrs'=> 0, 'flow'=>0.0, 'recorded_on'=>$hr_time->toDateTimeString()];
      $all_day[$i] = $row;
      $hr_time->subhours(1);
    }

    // overwrite the average Gasflow with the data from the actual
    // table get. Note we are putting the order to be the most recennt hour last.
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    for ( $i=0; $i < sizeof($r); $i++) {
      $trec = new Carbon($r[$i]->recorded_on);
      $trec->minute=0;
      $trec->second=0;
      $index = $hr_time->diffInHours($trec);

      $all_day[$index]['flow'] =
        sprintf("%5.2f",$r[$i]->flow);
    }

    // the hydrate function will put our constructed array into the
    // Collection format that we need
    $this->gasflows = Gasflow::hydrate($all_day);
    //dd($this->gasflows);
  }


  /**
   * Read the gas flow measurement records from the table for a specific deviceid
   * parameter. The records are stored in the class, loaded in descending order
   * by dateTime.  In other words the most recent first.
   * The date the most recent record was recorded is returned.
   *
   * @param string $id The deviceid ex. '00002'
   * @param int $data_size = 3  Number of hours of data (3 or 24)
   *
   * @throws Exception if SQL select fails (no records is ok though)
   *
   * @return Carbon datetime of last record
   */
  public function getGasflowData( $id, $data_size=3 )
  {
    $deviceid = Bioreactor::formatDeviceid($id); // format to 00000

    // get the last data entry record. Use the record_on time
    // and go backwards from that time to retrieve records
    try {
      $most_recent_measurement = Gasflow::where('deviceid', '=', $deviceid)->orderBy('recorded_on', 'desc')->first();
      if ( is_null($most_recent_measurement)) {
        App::abort(404);
      }
    }
    catch (\Exception $e) {
      $start_time = Carbon::now();
      return $start_time;
    }
    $last_time = new Carbon($most_recent_measurement->recorded_on);

    // subtract # of hours. We need to use a new Carbon or it will
    // just point at the old one anyways!
    $start_time = new Carbon($last_time);
    $start_time->subHours($data_size);

    // load the measurement data for this site
    try {
      if ( $data_size==24) {
        $this->_getHourlySummaryGasflowData($deviceid,$start_time,$last_time);
      }
      else {
        $this->gasflows = Gasflow::where('deviceid', '=', $deviceid)->where('recorded_on', '>', $start_time->toDateTimeString() )->orderBy('recorded_on', 'desc')->get();
      }
    }
    catch (\Exception $e) {
      $message = Lang::get('export.no_gasflow_data_found');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }

    //dd($this->gasflows);
    return $last_time;
  }

  /**
   * Build x and y, time and measurement arrays for recorded gas flows
   *
   * Data structured to be compatible with the javascript chart builder.
   *
   * The Gasflow records must already have been loaded into the Gasflow
   * Collection in this class
   *
   * @param string $x_axis_style ='default' 'default' is time. 'dot' is a dot
   *
   * @throws Exception if Gasflow has not been loaded from table yet
   *
   * @return Array 2 entries, with x and y Gasflow data
   */
  public function _buildXYGasflowData( $x_axis_style='default' )
  {
    // put the data in the correct form for the charts JS library
    // generate an x and y array
    // x holds time labels in hh:mm format
    // y holds gas flow measurements in nnnnn.nn format

    $this->x_gasflows = [];
    $this->y_gasflows = [];

    // abort if the gasflows have not been loaded
    // indicates that getgasflow data has not been called
    if ( ! is_null( $this->gasflows ) && count( $this->gasflows ) > 0 ) {

      // reverse the order to make the graph more human like
      $rev_gasflow = $this->gasflows->reverse();

      foreach ($rev_gasflow as $gasflow) {

        $dt = new carbon( $gasflow->recorded_on );

        switch( $x_axis_style )
        {
        case 'dot':
          $this->x_gasflows[] = '.';
          break;
        default:
          $this->x_gasflows[] = $dt->format( 'h:i' );
          break;
        }
        $this->y_gasflows[] = sprintf( "%5.2f", 10.0 * $gasflow->flow );
      }
    }

    // just put something in if there is no data
    // otherwise no graph will be generated
    if ( is_null( $this->gasflows ) || ( count( $this->gasflows ) < 1 ))
    {
      $this->x_gasflows[] = '0';
      $this->y_gasflows[] = 0;
    }

    //dd($this->x_gasflows);
    //dd($this->y_gasflows);

    return [
      'x_data' => $this->x_gasflows,
      'y_data' => $this->y_gasflows
    ];
  }

  /**
   * Read the pH measurement records from the table for a specific deviceid
   * parameter. The records are summarized by the hour
   *
   * @param string $id The deviceid ex. '00002'
   * @param Carbon $start_time date and time to read records after
   * @param Carbon $last_time date and time of most recent record
   *
   * @return null
   */
  protected function _getHourlySummaryPhreadingData( $deviceid, $start_time, $last_time )
  {
    // using raw call to the DB. I can't see how to do it using Eloquent
    // so going back to basics.
    // truncates all the recorded_on details down to just the hour.
    // In other words we are summarizing the results down to the average
    // over the hour.
    $r=DB::table('phreadings')
      ->select('deviceid', 'recorded_on',
      DB::raw('strftime("%H",time(recorded_on)) as hrs'),
      DB::raw('sum(ph)/count(*) as ph'))
      ->groupBy('hrs')
      ->where('deviceid', '=', $deviceid)
      ->where('recorded_on', '>', $start_time->toDateTimeString() )
      ->get();

    // make a 24 element array to hold the x data points
    // The results of the above table get may be missing data so it
    // may not return 24 results. we need to put zero in first
    $all_day=[];
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    // the all_day array is an array of arrays. this is the format that we can use
    // to backfill the results into the eloquent format using the hydrate call
    for ( $i=0; $i < 24; $i++) {
      // for each hour, make an array holding the results
      $row = ['deviceid'=>$deviceid, 'hrs'=> 0, 'ph'=>'7.0', 'recorded_on'=>$hr_time->toDateTimeString()];
      $all_day[$i] = $row;
      $hr_time->subhours(1);
    }

    // overwrite the average phreadings with the data from the actual
    // table get. Note we are putting the order to be the most recent hour last.
    $hr_time = new Carbon($last_time);
    $hr_time->minute=0;
    $hr_time->second=0;

    for ( $i=0; $i < sizeof($r); $i++) {
      $trec = new Carbon($r[$i]->recorded_on);
      $trec->minute=0;
      $trec->second=0;
      $index = $hr_time->diffInHours($trec);

      $all_day[$index]['ph'] =
        sprintf("%02.2f",$r[$i]->ph);
    }

    // the hydrate function will put our constructed array into the
    // Collection format that we need
    $this->phreadings = Phreading::hydrate($all_day);
    //dd($this->phreadings);
  }


  /**
   * Read the pH measurement records from the table for a specific deviceid
   * parameter. The records are stored in the class, loaded in descending order
   * by dateTime.  In other words the most recent first.
   * The date the most recent record was recorded is returned.
   *
   * @param string $id The deviceid ex. '00002'
   * @param int $data_size = 3  Number of hours of data (3 or 24)
   *
   * @throws Exception if SQL select fails (no records is ok though)
   *
   * @return Carbon datetime of last record
   */
  public function getPhreadingData( $id, $data_size=3 )
  {
    $deviceid = Bioreactor::formatDeviceid($id); // format to 00000

    // get the last data entry record. Use the record_on time
    // and go backwards from that time to retrieve records
    try {
      $most_recent_measurement = Phreading::where('deviceid', '=', $deviceid)->orderBy('recorded_on', 'desc')->first();
      if ( is_null($most_recent_measurement)) {
        App::abort(404);
      }
    }
    catch (\Exception $e) {
      $start_time = Carbon::now();
      return $start_time;
    }
    $last_time = new Carbon($most_recent_measurement->recorded_on);

    // subtract # of hours. We need to use a new Carbon or it will
    // just point at the old one anyways!
    $start_time = new Carbon($last_time);
    $start_time->subHours($data_size);

    // load the measurement data for this site
    try {
      if ( $data_size==24 ) {
        $this->_getHourlySummaryPhreadingData($deviceid,$start_time,$last_time);
      }
      else {
        $this->phreadings = Phreading::where('deviceid', '=', $deviceid)->where('recorded_on', '>', $start_time->toDateTimeString() )->orderBy('recorded_on', 'desc')->get();
      }
    }
    catch (\Exception $e) {
      $message = Lang::get('export.no_phreading_data_found');
      dd($message);
      //return Redirect::to('error')->with('message', $message);
    }

    //dd($this->phreadings);
    return $last_time;
  }

  /**
   * Builds the x and y Phreading graph arrays that are passed to the
   * javascript Chart builder. The Phreading records must already
   * have been loaded into the Phreading Collection in this class
   *
   * @param string $x_axis_style ='default' 'default' is time. 'dot' is a dot
   *
   * @throws Exception if Phreading have not been loaded from table yet
   *
   * @return Array Mixed  x and y Phreading chart data
   */
  public function _buildXYPhreadingData($x_axis_style='default')
  {

    // put the data in the correct form for the charts JS library
    // generate an x and y array
    // x holds time labels in hh:mm format
    // y holds y_phreadings as nnnnnn.n format

    $this->x_phreadings = [];
    $this->y_phreadings = [];

    // abort if the phreadings have not been loaded
    // indicates that getphreading data has not been called
    if ( ! is_null ($this->phreadings) && count($this->phreadings)>0) {

      // reverse the order to make the graph more human like
      $rev_ph = $this->phreadings->reverse();

      foreach ($rev_ph as $phreading) {

        $dt = new carbon($phreading->recorded_on);

        switch($x_axis_style)
        {
          case 'dot':
            $this->x_phreadings[] = '.';
            break;
          default:
            $this->x_phreadings[] = $dt->format('h:i');
            break;
        }
        $this->y_phreadings[] = sprintf("%6.1f",$phreading->ph);
      }
    }

    // just put something in if there is no data
    // otherwise no graph will be generated
    if (is_null ($this->phreadings) || (count($this->phreadings) < 1) )
    {
      $this->x_phreadings[]='0';
      $this->y_phreadings[]=7;
    }

    return [
      'x_data' => $this->x_phreadings,
      'y_data' => $this->y_phreadings
    ];
  }

}
