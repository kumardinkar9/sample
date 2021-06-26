<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EventType;
use App\Models\EventTimes;
use App\Models\EventLocation;
use App\Models\AttendedEvents;
use App\Models\Event;
use App\Models\Mailing;
use App\Models\TestListEmail;
use App\Models\EventWatsonDetails;
use App\Models\WatsonQueryList;
use Carbon\Carbon;
use Validator;
use DB;
use App\Watson\silverpop;
use App\Models\CronLogs;
use App\Token\token;
use Mail;
use Illuminate\Mail\Mailable;
use App\User;
use Artisan;
use \Venturecraft\Revisionable\Revision;
use App\Models\EventTargetStagedRevisions;

class EventController extends Controller {

  /**
   * Display a listing of the resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function index() {

    $event = Event::with('eventLocation.eventTimes')->with('eventAttended')->with('eventTimes')->with('eventTargetStaged')
            ->where(['status' => 1])
            ->orderBy('event_start_date', 'asc')
            ->get()->toArray();

    foreach ($event as $key => $value) {
      $attendedUserCount = count($value['event_attended']);
      $event_type = EventType::where('event_type_id', $value['event_type_id'])
              ->pluck('event_type')->first();
      $tracked_url = Mailing::where([['event_id', $value['event_id']], ['template_id', 1]])
              ->pluck('tracked_hyperlink')->first();

      $event[$key]['title'] = $event[$key]['event_name'];

      if ($value['event_type_id'] == 2 && !empty($value['registrant_limit']) && $attendedUserCount >= $value['registrant_limit']) {
        $event[$key]['registrant_limit'] =true;
      } else {
        $event[$key]['registrant_limit'] = false;
      }
      
      $event[$key]['timestamp'] = strtotime($value['event_start_date']);
      $event[$key]['timestampEndDate'] = strtotime($value['event_end_date']);
      $event[$key]['event_type'] = $event_type;
      $event[$key]['tracked_url'] = $tracked_url;
      $event_start_day = date('l', strtotime($value['event_start_date']));
      $event_start_date = date('F j', strtotime($value['event_start_date']));
      $event_start_year = date('Y', strtotime($value['event_start_date']));
      $date = date('Y-m-d', strtotime($value['event_start_date']));

      $event[$key]['event_start_date'] = $event_start_date;
      $event[$key]['event_start_year'] = $event_start_year;
      $event[$key]['event_start_day'] = $event_start_day;

      $event_end_day = date('l', strtotime($value['event_end_date']));
      $event_end_date = date('F j', strtotime($value['event_end_date']));
      $event_end_year = date('Y', strtotime($value['event_end_date']));

      $event[$key]['event_end_date'] = $event_end_date;
      $event[$key]['event_end_day'] = $event_end_day;
      $event[$key]['event_end_year'] = $event_end_year;
      $event[$key]['date'] = $date;
      // By DK - event end date format for multi date event
      $event[$key]['eventEndDate'] = date('Y-m-d', strtotime($value['event_end_date']));

      unset($event[$key]['event_name']);
      
      unset($event[$key]['event_approved']);
      unset($event[$key]['contact_list_id']);
      unset($event[$key]['invite_query_id']);
      unset($event[$key]['added_on']);
      unset($event[$key]['updated_on']);
      unset($event[$key]['status']);
      if (!$value['event_location']) {
        unset($event[$key]['event_location']);
        foreach ($value['event_times'] as $event_tim_key => $event_tim_val) {
          $event[$key]['event_times'][$event_tim_key]['event_start_time'] = date('g:i', strtotime($value['event_times'][$event_tim_key]['event_start_time']));
          $event[$key]['event_times'][$event_tim_key]['event_start_ampmStr'] = date('A', strtotime($value['event_times'][$event_tim_key]['event_start_time']));
          $event[$key]['event_times'][$event_tim_key]['event_end_time'] = date('g:i', strtotime($value['event_times'][$event_tim_key]['event_end_time']));
          $event[$key]['event_times'][$event_tim_key]['event_end_ampmStr'] = date('A', strtotime($value['event_times'][$event_tim_key]['event_end_time']));
          $event[$key]['event_times'][$event_tim_key]['event_full_time'] = date('g:i A', strtotime($value['event_times'][$event_tim_key]['event_start_time'])) . ' - ' . date('g:i A', strtotime($value['event_times'][$event_tim_key]['event_end_time'])) . ' ' . $value['event_times'][$event_tim_key]['event_timezone'];
        }
      }
      else {
        foreach ($value['event_location'] as $event_loc_key => $event_loc_val) {
          unset($event[$key]['event_location'][$event_loc_key]['event_id']);
          unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_times_id']);
          unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_id']);
          unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_location_id']);
          unset($event[$key]['event_times']);
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_start_time'] = date('g:i', strtotime($event_loc_val['event_times']['event_start_time']));
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_start_ampmStr'] = date('A', strtotime($event_loc_val['event_times']['event_start_time']));

          $event[$key]['event_location'][$event_loc_key]['city_state'] = $event_loc_val['location_name'] . ' - ' . $event_loc_val['location_city'] . ', ' . $event_loc_val['location_state'];
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_end_time'] = date('g:i', strtotime($event_loc_val['event_times']['event_end_time']));
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_end_ampmStr'] = date('A', strtotime($event_loc_val['event_times']['event_end_time']));
        }
      }
      if (isset($value['event_target_staged']['specialty'])) {
        $specialtyArr = explode(',',$value['event_target_staged']['specialty']);
        $event[$key]['event_specialty'] = $specialtyArr;
        unset($event[$key]['event_target_staged']);
      }
    }
    $most_recent_date = "";
    foreach ($event as $key => $value) {
      $now = strtotime(date("Y/m/d 00:00:00"));
      //      $now = time();
      //      dd($value['timestamp']);
      if ($now <= $value['timestamp']) {
        $most_recent_date = $value['date'];
        break;
      }

      if ($now > $value['timestamp']) {
        $most_recent_date = $value['date'];
      } 
    }
    $firstCol = [];
    $secondCol = [];
    
    foreach ($event as $key => $value) {
      $now = strtotime(date("Y/m/d 00:00:00"));

      if (($now <= $value['timestamp']) || ($now <=$value['timestampEndDate'])) {
        $firstCol[] = $value;
      }

      if ($now > $value['timestampEndDate']) {
        $secondCol[] = $value;
      } 
    }

    usort($firstCol, 'static::date_compare_future');
    usort($secondCol, 'static::date_compare_past');


    $event = array_merge($firstCol, $secondCol);

    return response()->json(['data' => $event, 'most_recent_date' => $most_recent_date]);
  }

  /**
   * Show the form for creating a new resource.
   *
   * @return \Illuminate\Http\Response
   */
  public function create() {
    //
  }

  /**
   * Store a newly created resource in storage.
   *
   * @param  \Illuminate\Http\Request  $request
   * @return \Illuminate\Http\Response
   */
  public function store(Request $request) {

    if ($request->event_type) {
      $event_type_id = EventType::where('event_type', '=', $request->event_type)->pluck('event_type_id');
    }
    $flag = 'fail';
    $event = new Event;
    $event->event_type_id = isset($event_type_id) ? $event_type_id[0] : NULL;
    $event->event_name = isset($request->event_name) ? $request->event_name : NULL;
    $event->event_description = isset($request->event_description) ? $request->event_description : NULL;
    $event->event_start_date = isset($request->event_start_date) ? $request->event_start_date . " 00:00:00" : NULL;
    $event->event_end_date = isset($request->event_end_date) ? $request->event_end_date . " 00:00:00" : NULL;
    $event->event_url = isset($request->event_url) ? $request->event_url : NULL;
    $event->event_followup_url = isset($request->event_followup_url) ? $request->event_followup_url : NULL;
    $event->event_foodserved_chck = ($request->event_foodserved_chck) ? 1 : 0;
    $event->event_followup_chck = ($request->event_followup_chck) ? 1 : 0;
    $event->user_id = $request->user_id;
    $event->added_on = Carbon::now()->toDateTimeString();
    $event->updated_on = Carbon::now()->toDateTimeString();
    $event->status = 0;
    $event->event_approved = 0;

    // Followup Email Checkbox
    $event->followup_email_checkbox = !empty($request->followup_email_checkbox) ? 1 : 0;
    
    //registrant_limit
    $event->registrant_limit = isset($request->registrant_limit) ? $request->registrant_limit : NULL;

    if (isset($request->event_times) && isset($request->event_location)) {
      return response()->json(['status' => 'fail']);
    }
    if ($request->event_type == 'Webinar') {

      $rules = [
        'event_name' => 'required|unique:event',
        'event_description' => 'required',
        'event_start_date' => 'required',
        'event_url' => 'required',
        'event_type' => 'required',
        'user_id' => 'required',
        'event_times' => 'array|min:1'
      ];
      foreach ($request->get('event_times') as $key => $val) {
        $rules['event_times.' . $key . '.event_start_time'] = 'required';
        $rules['event_times.' . $key . '.event_end_time'] = 'required';
        $rules['event_times.' . $key . '.event_timezone'] = 'required';
      }

      $validator = Validator::make($request->all(), $rules);
      if ($validator->fails()) {
        $message = $validator->errors();
        $error = $message->toArray();
        return response()->json([$error]);
      }
      $event->save();
      $count = 1;
      foreach ($request->event_times as $key => $value) {
        $event_times = new EventTimes;
        $event_times->event_id = $event->event_id;
        $event_times->event_location_id = 0;
        $event_times->event_start_time = isset($value['event_start_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $value['event_start_time'])) : NULL;
        $event_times->event_end_time = isset($value['event_end_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $value['event_end_time'])) : NULL;
        $event_times->event_timezone = isset($value['event_timezone']) ? $value['event_timezone'] : NULL;
        if ($event_times->save() && $count == count($request->event_times)) {
          $flag = 'success';
        }
        $count ++;
      }
    }
    elseif ($request->event_type == 'Live event' || $request->event_type == 'Convention') {
      $rules = [
        'event_name' => 'required|unique:event',
        'event_description' => 'required',
        'event_start_date' => 'required',
          //        'event_url' => 'required',
        'user_id' => 'required',
        'event_type' => 'required',
        'event_times' => 'array|min:1'
      ];
      foreach ($request->get('event_location') as $key => $val) {
        $rules['event_location.' . $key . '.location_name'] = 'required';
        $rules['event_location.' . $key . '.location_address1'] = 'required';
        $rules['event_location.' . $key . '.location_city'] = 'required';
        $rules['event_location.' . $key . '.location_state'] = 'required';
        $rules['event_location.' . $key . '.location_zip'] = 'required';
        $rules['event_location.' . $key . '.event_times.event_start_time'] = 'required';
        $rules['event_location.' . $key . '.event_times.event_end_time'] = 'required';
        $rules['event_location.' . $key . '.event_times.event_timezone'] = 'required';
      }

      $validator = Validator::make($request->all(), $rules);
      if ($validator->fails()) {
        $message = $validator->errors();
        $error = $message->toArray();
        return response()->json([$error]);
      }
      $event->save();
      foreach ($request->event_location as $loc_key => $loc_val) {
        $event_location = new EventLocation;
        $event_location->event_id = $event->event_id;
        $event_location->location_name = isset($loc_val['location_name']) ? $loc_val['location_name'] : NULL;
        $event_location->location_address1 = isset($loc_val['location_address1']) ? $loc_val['location_address1'] : NULL;
        $event_location->location_address2 = isset($loc_val['location_address2']) ? $loc_val['location_address2'] : NULL;
        $event_location->location_city = isset($loc_val['location_city']) ? $loc_val['location_city'] : NULL;
        $event_location->location_state = isset($loc_val['location_state']) ? $loc_val['location_state'] : NULL;
        $event_location->location_zip = isset($loc_val['location_zip']) ? $loc_val['location_zip'] : NULL;
        $event_location->location_phone = isset($loc_val['location_phone']) ? $loc_val['location_phone'] : NULL;
        $event_location->additional_message = isset($loc_val['additional_message']) ? $loc_val['additional_message'] : NULL;
        $event_location->save();
        $event_times = new EventTimes;
        $event_times->event_id = $event->event_id;
        $event_times->event_location_id = $event_location->event_location_id;
        $event_times->event_start_time = isset($loc_val['event_times']['event_start_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $loc_val['event_times']['event_start_time'])) : NULL;
        $event_times->event_end_time = isset($loc_val['event_times']['event_end_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $loc_val['event_times']['event_end_time'])) : NULL;
        $event_times->event_timezone = isset($loc_val['event_times']['event_timezone']) ? $loc_val['event_times']['event_timezone'] : NULL;
        if ($event_times->save()) {
          $flag = 'success';
        }
      }
    }
    if ($flag == 'success') {
      $testListEmail = TestListEmail::get()->toArray();
      if (isset($testListEmail)) {
        $eventDetail = [];

        foreach ($testListEmail as $key => $value) {
          $eventDetail[$key]['email'] = $value['email'];
          $eventDetail[$key]['eventName'] = $event->event_name;
          $eventDetail[$key]['eventDesc'] = $event->event_description;
          $eventDetail[$key]['eventEndTime'] = date('g:i A', strtotime($event_times->event_end_time));
          $eventDetail[$key]['eventStartTime'] = date('g:i A', strtotime($event_times->event_start_time));
          $eventDetail[$key]['eventLocationAddress'] = isset($event_location->location_address1)?$event_location->location_address1:'';
          $eventDetail[$key]['eventLocationAdditionalMsg'] = isset($event_location->additional_message)?$event_location->additional_message:'';
          $eventDetail[$key]['eventLocationCity'] = isset($event_location->location_city)?$event_location->location_city:'';
          $eventDetail[$key]['eventLocationName'] = isset($event_location->location_name)?$event_location->location_name:'';
          $eventDetail[$key]['eventLocationPhone'] = isset($event_location->location_phone)?$event_location->location_phone:'';
          $eventDetail[$key]['eventLocationState'] = isset($event_location->location_state)?$event_location->location_state:'';
          $eventDetail[$key]['eventLocationZip'] = isset($event_location->location_zip)?$event_location->location_zip:'';
        }
      }

      $eventName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $event->event_name);
      $silverpopData = new silverpop();
      $silverpopData = $silverpopData->createContactList($eventName, $eventDetail);

      $event->eventRelationalTable()->create([
        'watson_contact_id' =>isset($silverpopData['contactListId'])?$silverpopData['contactListId']:NULL,
        'watson_rt_id' =>isset($silverpopData['relationalData']['rTableId'])?$silverpopData['relationalData']['rTableId']:NULL,
        'watson_rt_query_name' =>isset($silverpopData['relationalData']['queryName'])?$silverpopData['relationalData']['queryName']:NULL,
        'watson_rt_query_id' =>isset($silverpopData['relationalData']['queryId'])?$silverpopData['relationalData']['queryId']:NULL
      ]);
      return response()->json([['status' => 'success'], ['event_id' => $event->event_id]]);
    }
    else {
      return response()->json(['status' => 'fail']);
    }
  }

  /**
   * Display the specified resource.
   *
   * @param  int  $id
   * @return \Illuminate\Http\Response
   */
  public function show($id) {

    $event = Event::with('eventLocation.eventTimes')->with('eventTimes')->with('eventTargetStaged')->with('eventMailing')
            ->where([['event_id', '=', $id]])
            ->get()->toArray();

    foreach ($event as $key => $value) {
      $event_type = EventType::where('event_type_id', $value['event_type_id'])
              ->pluck('event_type')->first();
      
      //if($event[$key]['status']){
        //$event[$key]['event_registered_users_count'] = count(AttendedEvents::where([['event_id', '=', $id]])->get()->toArray());
      // }
      
      $event[$key]['event_registered_users_count'] = ($value['event_type_id'] != 3) ? count(AttendedEvents::where([['event_id', '=', $id]])->get()->toArray()): 0;

      $event[$key]['event_name'] = $event[$key]['event_name'];

      //webinar type check
      if ($value['event_type_id'] == 2) {
        $event[$key]['registrant_limit'] = !empty($event[$key]['registrant_limit']) ? $event[$key]['registrant_limit']: NULL;
      } else {
        unset($event[$key]['registrant_limit']);
      }

      $event[$key]['event_type'] = $event_type;
      $event[$key]['event_start_date'] = date('Y-m-d', strtotime($value['event_start_date']));
      $event[$key]['event_end_date'] = date('Y-m-d', strtotime($value['event_end_date']));
      unset($event[$key]['event_type_id']);
      unset($event[$key]['contact_list_id']);
      unset($event[$key]['invite_query_id']);
      unset($event[$key]['added_on']);
      unset($event[$key]['updated_on']);
      if (!$value['event_location']) {
        foreach ($value['event_times'] as $event_tim_key => $event_tim_val) {
          unset($event[$key]['event_times'][$event_tim_key]['event_id']);
          // unset($event[$key]['event_times'][$event_tim_key]['event_times_id']);
          unset($event[$key]['event_times'][$event_tim_key]['event_location_id']);
          $event[$key]['event_times'][$event_tim_key]['event_start_time'] = date('g:i A', strtotime($value['event_times'][$event_tim_key]['event_start_time']));
          $event[$key]['event_times'][$event_tim_key]['event_end_time'] = date('g:i A', strtotime($value['event_times'][$event_tim_key]['event_end_time']));
        }
      }
      else {
        foreach ($value['event_location'] as $event_loc_key => $event_loc_val) {
          unset($event[$key]['event_location'][$event_loc_key]['event_id']);
          unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_times_id']);
          unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_id']);
          // unset($event[$key]['event_location'][$event_loc_key]['event_times']['event_location_id']);
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_start_time'] = date('g:i A', strtotime($event_loc_val['event_times']['event_start_time']));
          $event[$key]['event_location'][$event_loc_key]['event_times']['event_end_time'] = date('g:i A', strtotime($event_loc_val['event_times']['event_end_time']));
          unset($event[$key]['event_times']);
        }
      }
      if ($value['event_mailing']) {
        foreach ($value['event_mailing'] as $event_mail_key => $event_mail_val) {
          if ($event[$key]['event_mailing'][$event_mail_key]['send_date']) {
            
            $event[$key]['event_mailing'][$event_mail_key]['send_date'] = date('Y-m-d', strtotime($event_mail_val['send_date']));
          }
        }
      }
    }
    return response()->json(['status' => 'success', 'event_detail' => $event]);
  }

  /**
   * Show the form for editing the specified resource.
   *
   * @param  int  $id
   * @return \Illuminate\Http\Response
   */
  public function edit($id) {
    //
  }

  /**
   * Update the specified resource in storage.
   *
   * @param  \Illuminate\Http\Request  $request
   * @param  int  $id
   * @return \Illuminate\Http\Response
   */
  public function update(Request $request, $id) {

    if ($request->user_role == 'admin' && $request->status == 1) {
      $event = Event::find($id);
      $user = User::find($request->user_id);
      $event->status = 1;
      $evenyPublishedFirst = $event->event_published_first;

      //SFTP Column update on event published
      if ($evenyPublishedFirst == 0) {
          $event->sftp_file = $event->event_name;
      }
      
      // $event->event_published_first = 1;
      $event->updated_on = Carbon::now()->toDateTimeString();
      if ($event->save()) {

        //SilverPop Create Query List
        $targetAudience = $event::with('eventTargetStaged')->where('event_id',$id)->get()->toArray();

        $specialtyData = $targetAudience[0]['event_target_staged']['specialty'];
        $credentialData = $targetAudience[0]['event_target_staged']['credential'];
        $stateData = $targetAudience[0]['event_target_staged']['state'];

        if ($targetAudience[0]['event_target_staged']['invite_email_checkbox'] !== 1) {

          if ($evenyPublishedFirst == 1) {

            $EventTargetStagedRevisionsData = EventTargetStagedRevisions::where(['event_id' => $id])->latest('updated_at')->first();

            $specialtyDataRev = $EventTargetStagedRevisionsData->specialty ?? NULL;
            $credentialDataRev = $EventTargetStagedRevisionsData->credential ?? NULL;
            $stateDataRev = $EventTargetStagedRevisionsData->state ?? NULL;

            if ( ($specialtyData != $specialtyDataRev) || ($credentialData != $credentialDataRev) || ($stateData != $stateDataRev)) {
              $changeData = 1;
              $EventTargetStagedRevisions = new EventTargetStagedRevisions;
              $EventTargetStagedRevisions->event_id = $id;
              $EventTargetStagedRevisions->specialty = $specialtyData;
              $EventTargetStagedRevisions->credential = $credentialData;
              $EventTargetStagedRevisions->state = $stateData;
              $EventTargetStagedRevisions->created_at = Carbon::now()->toDateTimeString();
              $EventTargetStagedRevisions->updated_at = Carbon::now()->toDateTimeString();
              $EventTargetStagedRevisions->save();

            } else {
              $changeData = 0;
            }
          }

          if ($evenyPublishedFirst == 0 || $changeData) {

            $inviteMasterQueryId = EventWatsonDetails::where(['event_id' => $id])->pluck('watson_master_query_id');

            // Unique Token generate
            $uniqueId = new token;
            $uniqueId = $uniqueId->tokenGenerate(10);

            $eventName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $targetAudience[0]['event_name']).$uniqueId;

            $silverpopData = new silverpop();
            $silverpopData = $silverpopData->createQuery($credentialData, $specialtyData, $stateData, 'query-'.$eventName);

            if (!empty($inviteMasterQueryId[0]) && isset($silverpopData['queryListID'])) {

              $EventWatsonDetails = new WatsonQueryList;
              $EventWatsonDetails->event_id = $id;
              $EventWatsonDetails->watson_master_query_id = $inviteMasterQueryId[0];
              $EventWatsonDetails->save();
            }

            $event->eventRelationalTable()->update([
              'watson_master_query_id' =>isset($silverpopData['queryListID'])?$silverpopData['queryListID']: NULL,
            ]);
            $event->event_published_first = 1;
            $event->save();
          }

          //Manual invite mail if event date is of tomorrow.
          $tomorrowDate = date('Y-m-d 00:00:00', time() + 86400);

          if ($event->event_start_date <= $tomorrowDate) {
            //Artisan::call('schedule:mail');
            $inviteMailingData = Mailing::where(['event_id' => $id, 'template_id' => 1])->get()->toArray();
            //invite details
            $inviteTemplateId = $inviteMailingData[0]['silverpop_mailing_id'];
            $inviteMasterQueryId = EventWatsonDetails::where(['event_id' => $id])->pluck('watson_master_query_id');//$silverpopData['queryListID'];

            $uniqueId = new token;
            $uniqueId = $uniqueId->tokenGenerate(10);

            $inviteMailingName = 'ScheduleMailing -'.$uniqueId;
            $inviteSubject = $inviteMailingData[0]['subject_line'];
            $silverpopDataInvite = new silverpop();
            $silverpopDataInvite = $silverpopDataInvite->scheduleMailing($inviteTemplateId, $inviteMasterQueryId[0], $inviteMailingName, $inviteSubject);
            if ($silverpopDataInvite['status'] == 'TRUE') {
                // Mailing::where(['event_id' => $id, 'template_id' => 1])->update([
                //   'send_date' => date('Y-m-d 00:00:00', (time()))
                // ]);
                $cronLogs = new CronLogs();
                $cronLogs->template_id = 1;
                $cronLogs->event_id = $id;
                $cronLogs->mail_status = "Mail has been sent Succesfully";
                $cronLogs->mail_type = "Invite Mail-Event date is between 24hrs to cur. date";
                $cronLogs->save();
              } else {
                  $cronLogs = new CronLogs();
                  $cronLogs->template_id = 1;
                  $cronLogs->event_id = $id;
                  $cronLogs->mail_status = "Mail has not been sent Succesfully.".$silverpopDataInvite['faultString'];
                  $cronLogs->mail_type = "Invite Mail-Event date is between 24hrs to cur. date";
                  $cronLogs->save();
                  
                  $attnd_array['template_name'] = "Invite Mail";
                  $attnd_array['event_name'] = Event::where(['event_id' => $id])->pluck('event_name')[0];
                  $attnd_array['reason'] = $silverpopDataInvite['faultString'];
                  $attnd_array['email'] = ['xxxxx.xxxxxxx@xxxxxxxxxx.com'];
                  $attnd_array['email_subject'] = 'Invite Mail-Event date is between 24hrs to cur. date - Failed';
                  Mail::send('emails.cronjobFail', ['user' => $attnd_array], function($message) use ($attnd_array) {
                    $message->from(env("MAIL_FROM_ADDRESS_OWN"), env("MAIL_FROM_NAME"));
                    $message->replyTo(env("MAIL_REPLY_TO_ADDRESS_OWN"), env("MAIL_FROM_NAME"));
                    $message->to($attnd_array['email']);
                    // Add SMTP Header
                    $message->getSwiftMessage();
                    $message->getSwiftMessage()->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'XXXXXXXXXXX');
                    $message->subject($attnd_array['email_subject']);
                  });
              }
          }
        }
        return response()->json(['status' => 'success']);
      }
    }
    elseif ($request->user_role == 'admin' && $request->status == 0) {
      $event = Event::find($id);
      $user = User::find($request->user_id);
      $event->status = 0;
      $event->updated_on = Carbon::now()->toDateTimeString();
   
      if ($event->save()) {
            $events_array = Event::all();
            $events['live_events'] = $events['pending_events'] = $events['past_events'] = array();
            if ($events_array) {
                foreach ($events_array as $key => $value) {
                        if ((strtotime($value['event_start_date']) >= time()) && $value['status'] == 1) {
                            $events['live_events'][$key]['event_id'] = $value->event_id;
                            $events['live_events'][$key]['event_name'] = $value->event_name;
                            $events['live_events'][$key]['event_created_date'] = strtotime($value->added_on);
                            $events['live_events'][$key]['event_updated_date'] = strtotime($value->updated_on);
                        }
                        elseif ((strtotime($value['event_start_date']) >= time()) && $value['status'] != 1) {
                            $events['pending_events'][$key]['event_id'] = $value->event_id;
                            $events['pending_events'][$key]['event_name'] = $value->event_name;
                            $events['pending_events'][$key]['event_created_date'] = strtotime($value->added_on);
                            $events['pending_events'][$key]['event_updated_date'] = strtotime($value->updated_on);
                        }
                        elseif (time() > strtotime($value['event_start_date'])) {
                            $events['past_events'][$key]['event_id'] = $value->event_id;
                            $events['past_events'][$key]['event_name'] = $value->event_name;
                            $events['past_events'][$key]['event_created_date'] = strtotime($value->added_on);
                            $events['past_events'][$key]['event_updated_date'] = strtotime($value->updated_on);
                        }
                    }
                    $events['pending_events'] = array_values($events['pending_events']);
                    $events['live_events'] = array_values($events['live_events']);
                    $events['past_events'] = array_values($events['past_events']);
                }
                return response()->json(['status' => 'success', 'events' => $events]);
            }
        }
    else {
      if ($request->event_type) {
        $event_type_id = EventType::where('event_type', '=', $request->event_type)->pluck('event_type_id');
      }
      $flag = 'fail';
      $event = Event::find($id);

      //clone event number
      if ($request->clone_number) {
        $event->clone_number = $request->clone_number;
        $event->save();
        return response()->json([['status' => 'success'], ['event_id' => $event->event_id]]);
      }
      $mailing_send_date_change = 1;
      if(strtotime($event->event_start_date) == strtotime($request->event_start_date . " 00:00:00")){
        $mailing_send_date_change = 0;
      }

      $event->event_type_id = isset($event_type_id) ? $event_type_id[0] : NULL;
      $event->event_name = isset($request->event_name) ? $request->event_name : NULL;
      $event->event_description = isset($request->event_description) ? $request->event_description : NULL;
      $event->event_start_date = isset($request->event_start_date) ? $request->event_start_date . " 00:00:00" : NULL;
      $event->event_end_date = isset($request->event_end_date) ? $request->event_end_date . " 00:00:00" : NULL;
      $event->event_url = isset($request->event_url) ? $request->event_url : NULL;
      $event->event_followup_url = isset($request->event_followup_url) ? $request->event_followup_url : NULL;
      $event->event_foodserved_chck = ($request->event_foodserved_chck) ? 1 : 0;
      $event->event_followup_chck = ($request->event_followup_chck) ? 1 : 0;
      $event->user_id = $request->user_id;
      $event->updated_on = Carbon::now()->toDateTimeString();
      $event->status = 0;
      $event->event_approved = 0;

      // Followup Email Checkbox
      $event->followup_email_checkbox = !empty($request->followup_email_checkbox) ? 1 : 0;

      //registrant_limit
      $event->registrant_limit = isset($request->registrant_limit) ? $request->registrant_limit : NULL;

      if (isset($request->event_times) && isset($request->event_location)) {
        return response()->json(['status' => 'fail']);
      }
      $revisions = array();
      if ($request->event_type == 'Webinar') {
        $delete_loc = EventLocation::where('event_id',$id)->delete();
        $rules = [
          'event_name' => 'required|unique:event,event_name,'.$id.',event_id',
          'event_description' => 'required',
          'event_start_date' => 'required',
          'event_url' => 'required',
          'event_type' => 'required',
          'user_id' => 'required',
          'event_times' => 'array|min:1'
        ];
       // $rules['event_name'] = $rules['event_name'] . ',event_name,' . $id;
        foreach ($request->get('event_times') as $key => $val) {
          $rules['event_times.' . $key . '.event_start_time'] = 'required';
          $rules['event_times.' . $key . '.event_end_time'] = 'required';
          $rules['event_times.' . $key . '.event_timezone'] = 'required';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
          $message = $validator->errors();
          $error = $message->toArray();
          return response()->json([$error]);
        }
        $event->save();
        $remain_time_id = $time_ids = array();
        foreach($event->eventTimes as $time){
          $time_ids[] = $time->event_times_id;
        }
        foreach ($request->event_times as $key => $value) {
          if(empty($value['event_times_id'])){
            $event_times = new EventTimes;
            $event_times->event_id = $event->event_id;
            $event_times->event_location_id = 0;
            $event_times->event_start_time = isset($value['event_start_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $value['event_start_time'])) : NULL;
            $event_times->event_end_time = isset($value['event_end_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $value['event_end_time'])) : NULL;
            $event_times->event_timezone = isset($value['event_timezone']) ? $value['event_timezone'] : NULL;
            $event_times->save();
          }
          else{
            if(in_array($value['event_times_id'], $time_ids)){
              $remain_time_id[] = $value['event_times_id'];
            }
          }
        }
        $delete_times_id = array_diff($time_ids,$remain_time_id);
        foreach($delete_times_id as $delete_time_id){
          $delete_time = EventTimes::where('event_times_id',$delete_time_id)->first();
          $revisions[] = array(
                          'revisionable_type' => 'App\Models\EventTimes',
                          'revisionable_id' => $delete_time->event_times_id,
                          'key' => 'event_id, event_start_time, event_end_time, event_timezone',
                          'old_value' => $event->event_id.', '.$delete_time->event_start_time.', '.$delete_time->event_end_time.', '.$delete_time->event_timezone,
                          'new_value' => '',
                          'user_id' => $request->user_id,
                          'created_at' => new \DateTime(),
                          'updated_at' => new \DateTime(),
                        );
          EventTimes::where('event_times_id',$delete_time_id)->delete();
        }
        $flag = 'success';
      }
      elseif ($request->event_type == 'Live event' || $request->event_type == 'Convention') {
        $delete_time = EventTimes::where([['event_id',$id],['event_location_id',0]])->delete();
        $rules = [
          'event_name' => 'required|unique:event,event_name,'.$id.',event_id',
          'event_description' => 'required',
          'event_start_date' => 'required',
          'user_id' => 'required',
          'event_type' => 'required',
          'event_times' => 'array|min:1'
        ];
        foreach ($request->get('event_location') as $key => $val) {
          $rules['event_location.' . $key . '.location_name'] = 'required';
          $rules['event_location.' . $key . '.location_address1'] = 'required';
          $rules['event_location.' . $key . '.location_city'] = 'required';
          $rules['event_location.' . $key . '.location_state'] = 'required';
          $rules['event_location.' . $key . '.location_zip'] = 'required';
          $rules['event_location.' . $key . '.event_times.event_start_time'] = 'required';
          $rules['event_location.' . $key . '.event_times.event_end_time'] = 'required';
          $rules['event_location.' . $key . '.event_times.event_timezone'] = 'required';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
          $message = $validator->errors();
          $error = $message->toArray();
          return response()->json([$error]);
        }
        $event->save();
        
        $remain_location_id = $location_ids = array();
        foreach($event->eventLocation as $location){
          $location_ids[] = $location->event_location_id;
        }

        foreach ($request->event_location as $loc_key => $loc_val) {
          if(empty($loc_val['event_location_id'])){
            $event_location = new EventLocation;
            $event_location->event_id = $event->event_id;
            $event_location->location_name = isset($loc_val['location_name']) ? $loc_val['location_name'] : NULL;
            $event_location->location_address1 = isset($loc_val['location_address1']) ? $loc_val['location_address1'] : NULL;
            $event_location->location_address2 = isset($loc_val['location_address2']) ? $loc_val['location_address2'] : NULL;
            $event_location->location_city = isset($loc_val['location_city']) ? $loc_val['location_city'] : NULL;
            $event_location->location_state = isset($loc_val['location_state']) ? $loc_val['location_state'] : NULL;
            $event_location->location_zip = isset($loc_val['location_zip']) ? $loc_val['location_zip'] : NULL;
            $event_location->location_phone = isset($loc_val['location_phone']) ? $loc_val['location_phone'] : NULL;
            $event_location->additional_message = isset($loc_val['additional_message']) ? $loc_val['additional_message'] : NULL;
            $event_location->save();
            $event_times = new EventTimes;
            $event_times->event_id = $event->event_id;
            $event_times->event_location_id = $event_location->event_location_id;
            $event_times->event_start_time = isset($loc_val['event_times']['event_start_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $loc_val['event_times']['event_start_time'])) : NULL;
            $event_times->event_end_time = isset($loc_val['event_times']['event_end_time']) ? date("Y-m-d H:i:s", strtotime($request->event_start_date . " " . $loc_val['event_times']['event_end_time'])) : NULL;
            $event_times->event_timezone = isset($loc_val['event_times']['event_timezone']) ? $loc_val['event_times']['event_timezone'] : NULL;
            $event_times->save();
          }
          else{
            if(in_array($loc_val['event_location_id'], $location_ids)){
              $remain_location_id[] = $loc_val['event_location_id'];
            }
          }
        }
        $delete_location_id = array_diff($location_ids,$remain_location_id);
        foreach($delete_location_id as $delete_loc_id){
          $delete_loc = EventLocation::where('event_location_id',$delete_loc_id)->first();
          $delete_time = EventTimes::where('event_location_id',$delete_loc_id)->first();
          $revisions[] = array(
                          'revisionable_type' => 'App\Models\EventLocation',
                          'revisionable_id' => $delete_loc->event_location_id,
                          'key' => 'event_id, location_name, location_address1, location_city, location_state, location_zip, location_phone, additional_message, event_start_time, event_end_time, event_timezone',
                          'old_value' => $event->event_id.', '.$delete_loc->location_name.', '.$delete_loc->location_address1.', '.$delete_loc->location_city.', '.$delete_loc->location_state.', '.$delete_loc->location_zip.', '.$delete_loc->location_phone.', '.$delete_loc->additional_message.', '.$delete_time->event_start_time.', '.$delete_time->event_end_time.', '.$delete_time->event_timezone,
                          'new_value' => '',
                          'user_id' => $request->user_id,
                          'created_at' => new \DateTime(),
                          'updated_at' => new \DateTime(),
                        );

          EventLocation::where('event_location_id',$delete_loc_id)->delete();
          EventTimes::where('event_location_id',$delete_loc_id)->delete();
        }
        $flag = 'success';
      }
      $revision = new Revision;
      \DB::table($revision->getTable())->insert($revisions);
      if ($flag == 'success') {
        return response()->json([['status' => 'success'], ['event_id' => $event->event_id]]);
      }
      else {
        return response()->json(['status' => 'fail']);
      }
    }
  }

  /**
   * Remove the specified resource from storage.
   *
   * @param  int  $id
   * @return \Illuminate\Http\Response
   */
  public function destroy($id) {
    //
  }

    // Comparison function 
  public static function date_compare_future($element1, $element2) { 
      $datetime1 = $element1['timestamp']; 
      $datetime2 = $element2['timestamp']; 
      return $datetime1 - $datetime2; 
  } 

  // Comparison function 
  public static function date_compare_past($element1, $element2) { 
      $datetime1 = $element1['timestamp']; 
      $datetime2 = $element2['timestamp']; 
      return $datetime2 - $datetime1; 
  } 

}
