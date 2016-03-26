<?php
/**
 * This controller handles all actions related to Licenses and
 * License Seats for the Snipe-IT Asset Management application.
 *
 * PHP version 5.5.9
 * @package    Snipe-IT
 * @version    v1.0
 */

namespace App\Http\Controllers;

use Assets;

use Input;
use Lang;
use App\Models\License;
use App\Models\Asset;
use App\Models\User;
use App\Models\Actionlog;
use DB;
use Redirect;
use App\Models\LicenseSeat;
use App\Models\Depreciation;
use App\Models\Company;
use App\Models\Setting;
use App\Models\Supplier;
use Validator;
use View;
use Response;
use Slack;
use Config;
use Session;
use App\Helpers\Helper;
use Auth;

class LicensesController extends Controller
{
    /**
     * Show a list of all the licenses.
     *
     * @return View
     */




    public function getIndex()
    {
        // Show the page
        return View::make('licenses/index');
    }


    /**
     * License create.
     *
     * @return View
     */
    public function getCreate()
    {
        // Show the page
      //  $license_options = array('0' => 'Top Level') + License::lists('name', 'id');
        // Show the page
        $depreciation_list = Helper::depreciationList();
        $supplier_list = Helper::suppliersList();
        $maintained_list = array('' => 'Maintained', '1' => 'Yes', '0' => 'No');
        $company_list = Helper::companyList();

        return View::make('licenses/edit')
            //->with('license_options',$license_options)
            ->with('depreciation_list', $depreciation_list)
            ->with('supplier_list', $supplier_list)
            ->with('maintained_list', $maintained_list)
            ->with('company_list', $company_list)
            ->with('license', new License);
    }


    /**
     * License create form processing.
     *
     * @return Redirect
     */
    public function postCreate()
    {


        // get the POST data
        $new = Input::all();

        // create a new model instance
        $license = new License();

        if (e(Input::get('purchase_cost')) == '') {
            $license->purchase_cost =  null;
        } else {
            $license->purchase_cost = e(Input::get('purchase_cost'));
        }

        if (e(Input::get('supplier_id')) == '') {
            $license->supplier_id = null;
        } else {
            $license->supplier_id = e(Input::get('supplier_id'));
        }

        if (e(Input::get('maintained')) == '') {
            $license->maintained = 0;
        } else {
            $license->maintained = e(Input::get('maintained'));
        }

        if (e(Input::get('reassignable')) == '') {
            $license->reassignable = 0;
        } else {
            $license->reassignable = e(Input::get('reassignable'));
        }

        if (e(Input::get('purchase_order')) == '') {
            $license->purchase_order = '';
        } else {
            $license->purchase_order = e(Input::get('purchase_order'));
        }

        // Save the license data
        $license->name              = e(Input::get('name'));
        $license->serial            = e(Input::get('serial'));
        $license->license_email     = e(Input::get('license_email'));
        $license->license_name      = e(Input::get('license_name'));
        $license->notes             = e(Input::get('notes'));
        $license->order_number      = e(Input::get('order_number'));
        $license->seats             = e(Input::get('seats'));
        $license->purchase_date     = e(Input::get('purchase_date'));
        $license->purchase_order    = e(Input::get('purchase_order'));
        $license->depreciation_id   = e(Input::get('depreciation_id'));
        $license->company_id        = Company::getIdForCurrentUser(Input::get('company_id'));
        $license->expiration_date   = e(Input::get('expiration_date'));
        $license->user_id           = Auth::user()->id;

        if (($license->purchase_date == "") || ($license->purchase_date == "0000-00-00")) {
            $license->purchase_date = null;
        }

        if (($license->expiration_date == "") || ($license->expiration_date == "0000-00-00")) {
            $license->expiration_date = null;
        }

        if (($license->purchase_cost == "") || ($license->purchase_cost == "0.00")) {
            $license->purchase_cost = null;
        }

            // Was the license created?
        if ($license->save()) {

            $insertedId = $license->id;
          // Save the license seat data
            for ($x=0; $x<$license->seats; $x++) {
                $license_seat = new \App\Models\LicenseSeat();
                $license_seat->license_id       = $insertedId;
                $license_seat->user_id          = Auth::user()->id;
                $license_seat->assigned_to      = null;
                $license_seat->notes            = null;
                $license_seat->save();
            }


          // Redirect to the new license page
            return Redirect::to("admin/licenses")->with('success', Lang::get('admin/licenses/message.create.success'));
        }

        return Redirect::back()->withInput()->withErrors($license->getErrors());

    }

    /**
     * License update.
     *
     * @param  int  $licenseId
     * @return View
     */
    public function getEdit($licenseId = null)
    {
        // Check if the license exists
        if (is_null($license = License::find($licenseId))) {
            // Redirect to the blogs management page
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

        if ($license->purchase_date == "0000-00-00") {
            $license->purchase_date = null;
        }

        if ($license->purchase_cost == "0.00") {
            $license->purchase_cost = null;
        }

        // Show the page
        $license_options = array('' => 'Top Level') + DB::table('assets')->where('id', '!=', $licenseId)->pluck('name', 'id');
        $depreciation_list = array('0' => Lang::get('admin/licenses/form.no_depreciation')) + Depreciation::pluck('name', 'id')->toArray();
        $supplier_list = array('' => 'Select Supplier') + Supplier::orderBy('name', 'asc')->pluck('name', 'id')->toArray();
        $maintained_list = array('' => 'Maintained', '1' => 'Yes', '0' => 'No');
        $company_list = Helper::companyList();

        return View::make('licenses/edit', compact('license'))
            ->with('license_options', $license_options)
            ->with('depreciation_list', $depreciation_list)
            ->with('supplier_list', $supplier_list)
            ->with('company_list', $company_list)
            ->with('maintained_list', $maintained_list);
    }


    /**
     * License update form processing page.
     *
     * @param  int  $licenseId
     * @return Redirect
     */
    public function postEdit($licenseId = null)
    {
      // Check if the license exists
        if (is_null($license = License::find($licenseId))) {
            // Redirect to the blogs management page
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

      // Update the license data
        $license->name              = e(Input::get('name'));
        $license->serial            = e(Input::get('serial'));
        $license->license_email     = e(Input::get('license_email'));
        $license->license_name      = e(Input::get('license_name'));
        $license->notes             = e(Input::get('notes'));
        $license->order_number      = e(Input::get('order_number'));
        $license->depreciation_id   = e(Input::get('depreciation_id'));
        $license->company_id        = Company::getIdForCurrentUser(Input::get('company_id'));
        $license->purchase_order    = e(Input::get('purchase_order'));
        $license->maintained        = e(Input::get('maintained'));
        $license->reassignable      = e(Input::get('reassignable'));

        if (e(Input::get('supplier_id')) == '') {
            $license->supplier_id = null;
        } else {
            $license->supplier_id = e(Input::get('supplier_id'));
        }

      // Update the asset data
        if (e(Input::get('purchase_date')) == '') {
              $license->purchase_date =  null;
        } else {
              $license->purchase_date = e(Input::get('purchase_date'));
        }

        if (e(Input::get('expiration_date')) == '') {
            $license->expiration_date = null;
        } else {
            $license->expiration_date = e(Input::get('expiration_date'));
        }

        if (e(Input::get('termination_date')) == '') {
            $license->termination_date =  null;
        } else {
            $license->termination_date = e(Input::get('termination_date'));
        }

        if (e(Input::get('purchase_cost')) == '') {
              $license->purchase_cost =  null;
        } else {
              $license->purchase_cost = e(Input::get('purchase_cost'));
              //$license->purchase_cost = e(Input::get('purchase_cost'));
        }

        if (e(Input::get('maintained')) == '') {
            $license->maintained = 0;
        } else {
            $license->maintained = e(Input::get('maintained'));
        }

        if (e(Input::get('reassignable')) == '') {
            $license->reassignable = 0;
        } else {
            $license->reassignable = e(Input::get('reassignable'));
        }

        if (e(Input::get('purchase_order')) == '') {
            $license->purchase_order = '';
        } else {
            $license->purchase_order = e(Input::get('purchase_order'));
        }

        //Are we changing the total number of seats?
        if ($license->seats != e(Input::get('seats'))) {
          //Determine how many seats we are dealing with
            $difference = e(Input::get('seats')) - $license->licenseseats()->count();

            if ($difference < 0) {
                //Filter out any license which have a user attached;
                $seats = $license->licenseseats->filter(function ($seat) {
                    return is_null($seat->user);
                });


              //If the remaining collection is as large or larger than the number of seats we want to delete
                if ($seats->count() >= abs($difference)) {
                    for ($i=1; $i <= abs($difference); $i++) {
                        //Delete the appropriate number of seats
                        $seats->pop()->delete();
                    }

                  //Log the deletion of seats to the log
                    $logaction = new Actionlog();
                    $logaction->asset_id = $license->id;
                    $logaction->asset_type = 'software';
                    $logaction->user_id = Auth::user()->id;
                    $logaction->note = abs($difference)." seats";
                    $logaction->checkedout_to =  null;
                    $log = $logaction->logaction('delete seats');

                } else {
                  // Redirect to the license edit page
                    return Redirect::to("admin/licenses/$licenseId/edit")->with('error', Lang::get('admin/licenses/message.assoc_users'));
                }
            } else {

                for ($i=1; $i <= $difference; $i++) {
                  //Create a seat for this license
                    $license_seat = new LicenseSeat();
                    $license_seat->license_id       = $license->id;
                    $license_seat->user_id          = Auth::user()->id;
                    $license_seat->assigned_to      = null;
                    $license_seat->notes            = null;
                    $license_seat->save();
                }

                //Log the addition of license to the log.
                $logaction = new Actionlog();
                $logaction->asset_id = $license->id;
                $logaction->asset_type = 'software';
                $logaction->user_id = Auth::user()->id;
                $logaction->note = abs($difference)." seats";
                $log = $logaction->logaction('add seats');
            }
            $license->seats             = e(Input::get('seats'));
        }

        // Was the asset created?
        if ($license->save()) {
            // Redirect to the new license page
            return Redirect::to("admin/licenses/$licenseId/view")->with('success', Lang::get('admin/licenses/message.update.success'));
        }


        // Redirect to the license edit page
        return Redirect::to("admin/licenses/$licenseId/edit")->with('error', Lang::get('admin/licenses/message.update.error'));

    }

    /**
     * Delete the given license.
     *
     * @param  int  $licenseId
     * @return Redirect
     */
    public function getDelete($licenseId)
    {
        // Check if the license exists
        if (is_null($license = License::find($licenseId))) {
            // Redirect to the license management page
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        } elseif (!Company::isCurrentUserHasAccess($license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

        if (($license->assignedcount()) && ($license->assignedcount() > 0)) {

            // Redirect to the license management page
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.assoc_users'));

        } else {

            // Delete the license and the associated license seats
            DB::table('license_seats')
            ->where('id', $license->id)
            ->update(array('assigned_to' => null,'asset_id' => null));

            $licenseseats = $license->licenseseats();
            $licenseseats->delete();
            $license->delete();




            // Redirect to the licenses management page
            return Redirect::to('admin/licenses')->with('success', Lang::get('admin/licenses/message.delete.success'));
        }


    }


    /**
    * Check out the asset to a person
    **/
    public function getCheckout($seatId)
    {
        // Check if the asset exists
        if (is_null($licenseseat = LicenseSeat::find($seatId))) {
            // Redirect to the asset management page with error
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        } elseif (!Company::isCurrentUserHasAccess($licenseseat->license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

        // Get the dropdown of users and then pass it to the checkout view
         $users_list = array('' => 'Select a User') + DB::table('users')->select(DB::raw('concat(last_name,", ",first_name," (",username,")") as full_name, id'))->whereNull('deleted_at')->orderBy('last_name', 'asc')->orderBy('first_name', 'asc')->lists('full_name', 'id');


        // Left join to get a list of assets and some other helpful info
        $asset = DB::table('assets')
            ->leftJoin('users', 'users.id', '=', 'assets.assigned_to')
            ->leftJoin('models', 'assets.model_id', '=', 'models.id')
            ->select(
                'assets.id',
                'assets.name',
                'first_name',
                'last_name',
                'asset_tag',
                DB::raw('concat(first_name," ",last_name) as full_name, assets.id as id, models.name as modelname')
            )
            ->whereNull('assets.deleted_at')
            ->get();

            $asset_array = json_decode(json_encode($asset), true);
            $asset_element[''] = 'Please select an asset';

            // Build a list out of the data results
        for ($x=0; $x<count($asset_array); $x++) {

            if ($asset_array[$x]['full_name']!='') {
                $full_name = ' ('.$asset_array[$x]['full_name'].') '.$asset_array[$x]['modelname'];
            } else {
                $full_name = ' (Unassigned) '.$asset_array[$x]['modelname'];
            }
            $asset_element[$asset_array[$x]['id']] = $asset_array[$x]['asset_tag'].' - '.$asset_array[$x]['name'].$full_name;

        }

        return View::make('licenses/checkout', compact('licenseseat'))->with('users_list', $users_list)->with('asset_list', $asset_element);

    }



    /**
    * Check out the asset to a person
    **/
    public function postCheckout($seatId)
    {

        $licenseseat = LicenseSeat::find($seatId);
        $assigned_to = e(Input::get('assigned_to'));
        $asset_id = e(Input::get('asset_id'));
        $user = Auth::user();

        if (!Company::isCurrentUserHasAccess($licenseseat->license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

        // Declare the rules for the form validation
        $rules = array(

            'note'   => 'string',
            'asset_id'  => 'required_without:assigned_to',
        );

        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $rules);

        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            return Redirect::back()->withInput()->withErrors($validator);
        }

        if ($assigned_to!='') {
        // Check if the user exists
            if (is_null($is_assigned_to = User::find($assigned_to))) {
                // Redirect to the asset management page with error
                return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.user_does_not_exist'));
            }
        }

        if ($asset_id!='') {

            if (is_null($is_asset_id = Asset::find($asset_id))) {
                // Redirect to the asset management page with error
                return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.asset_does_not_exist'));
            }

            if (($is_asset_id->assigned_to!=$assigned_to) && ($assigned_to!='')) {
                //echo 'asset assigned to: '.$is_asset_id->assigned_to.'<br>license assigned to: '.$assigned_to;
                return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.owner_doesnt_match_asset'));
            }

        }



        // Check if the asset exists
        if (is_null($licenseseat)) {
            // Redirect to the asset management page with error
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        }

        if (Input::get('asset_id') == '') {
            $licenseseat->asset_id = null;
        } else {
            $licenseseat->asset_id = e(Input::get('asset_id'));
        }

        // Update the asset data
        if (e(Input::get('assigned_to')) == '') {
                $licenseseat->assigned_to =  null;

        } else {
                $licenseseat->assigned_to = e(Input::get('assigned_to'));
        }

        // Was the asset updated?
        if ($licenseseat->save()) {

            $logaction = new Actionlog();

            //$logaction->location_id = $assigned_to->location_id;
            $logaction->asset_type = 'software';
            $logaction->user_id = Auth::user()->id;
            $logaction->note = e(Input::get('note'));
            $logaction->asset_id = $licenseseat->license_id;


            $license = License::find($licenseseat->license_id);
            $settings = Setting::getSettings();


            // Update the asset data
            if (e(Input::get('assigned_to')) == '') {
                $logaction->checkedout_to = null;
                $slack_msg = strtoupper($logaction->asset_type).' license <'.config('app.url').'/admin/licenses/'.$license->id.'/view'.'|'.$license->name.'> checked out to <'.config('app.url').'/hardware/'.$is_asset_id->id.'/view|'.$is_asset_id->showAssetName().'> by <'.config('app.url').'/admin/users/'.$user->id.'/view'.'|'.$user->fullName().'>.';
            } else {
                $logaction->checkedout_to = e(Input::get('assigned_to'));
                $slack_msg = strtoupper($logaction->asset_type).' license <'.config('app.url').'/admin/licenses/'.$license->id.'/view'.'|'.$license->name.'> checked out to <'.config('app.url').'/admin/users/'.$is_assigned_to->id.'/view|'.$is_assigned_to->fullName().'> by <'.config('app.url').'/admin/users/'.$user->id.'/view'.'|'.$user->fullName().'>.';
            }



            if ($settings->slack_endpoint) {


                $slack_settings = [
                    'username' => $settings->botname,
                    'channel' => $settings->slack_channel,
                    'link_names' => true
                ];

                $client = new \Maknz\Slack\Client($settings->slack_endpoint, $slack_settings);

                try {
                        $client->attach([
                            'color' => 'good',
                            'fields' => [
                                [
                                    'title' => 'Checked Out:',
                                    'value' => $slack_msg
                                ],
                                [
                                    'title' => 'Note:',
                                    'value' => e($logaction->note)
                                ],



                            ]
                        ])->send('License Checked Out');

                } catch (Exception $e) {

                }

            }

            $log = $logaction->logaction('checkout');


            // Redirect to the new asset page
            return Redirect::to("admin/licenses")->with('success', Lang::get('admin/licenses/message.checkout.success'));
        }

        // Redirect to the asset management page with error
        return Redirect::to('admin/licenses/$assetId/checkout')->with('error', Lang::get('admin/licenses/message.create.error'))->with('license', new License);
    }


    /**
    * Check the license back into inventory
    **/
    public function getCheckin($seatId = null, $backto = null)
    {
        // Check if the asset exists
        if (is_null($licenseseat = LicenseSeat::find($seatId))) {
            // Redirect to the asset management page with error
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        } elseif (!Company::isCurrentUserHasAccess($licenseseat->license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }
        return View::make('licenses/checkin', compact('licenseseat'))->with('backto', $backto);

    }



    /**
    * Check in the item so that it can be checked out again to someone else
    **/
    public function postCheckin($seatId = null, $backto = null)
    {
        // Check if the asset exists
        if (is_null($licenseseat = LicenseSeat::find($seatId))) {
            // Redirect to the asset management page with error
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        }

        $license = License::find($licenseseat->license_id);

        if (!Company::isCurrentUserHasAccess($license)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

        if (!$license->reassignable) {
            // Not allowed to checkin
            Session::flash('error', 'License not reassignable.');
            return Redirect::back()->withInput();
        }

        // Declare the rules for the form validation
        $rules = array(
            'note'   => 'string',
            'notes'   => 'string',
        );

        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $rules);

        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            return Redirect::back()->withInput()->withErrors($validator);
        }
        $return_to = $licenseseat->assigned_to;
        $logaction = new Actionlog();
        $logaction->checkedout_to = $licenseseat->assigned_to;

        // Update the asset data
        $licenseseat->assigned_to                   = null;
        $licenseseat->asset_id                      = null;

        $user = Auth::user();

        // Was the asset updated?
        if ($licenseseat->save()) {
            $logaction->asset_id = $licenseseat->license_id;
            $logaction->location_id = null;
            $logaction->asset_type = 'software';
            $logaction->note = e(Input::get('note'));
            $logaction->user_id = $user->id;

            $settings = Setting::getSettings();

            if ($settings->slack_endpoint) {


                $slack_settings = [
                    'username' => $settings->botname,
                    'channel' => $settings->slack_channel,
                    'link_names' => true
                ];

                $client = new \Maknz\Slack\Client($settings->slack_endpoint, $slack_settings);

                try {
                        $client->attach([
                            'color' => 'good',
                            'fields' => [
                                [
                                    'title' => 'Checked In:',
                                    'value' => strtoupper($logaction->asset_type).' <'.config('app.url').'/admin/licenses/'.$license->id.'/view'.'|'.$license->name.'> checked in by <'.config('app.url').'/admin/users/'.$user->id.'/view'.'|'.$user->fullName().'>.'
                                ],
                                [
                                    'title' => 'Note:',
                                    'value' => e($logaction->note)
                                ],

                            ]
                        ])->send('License Checked In');

                } catch (Exception $e) {

                }

            }


            $log = $logaction->logaction('checkin from');



            if ($backto=='user') {
                return Redirect::to("admin/users/".$return_to.'/view')->with('success', Lang::get('admin/licenses/message.checkin.success'));
            } else {
                return Redirect::to("admin/licenses/".$licenseseat->license_id."/view")->with('success', Lang::get('admin/licenses/message.checkin.success'));
            }

        }

        // Redirect to the license page with error
        return Redirect::to("admin/licenses")->with('error', Lang::get('admin/licenses/message.checkin.error'));
    }

    /**
    *  Get the asset information to present to the asset view page
    *
    * @param  int  $licenseId
    * @return View
    **/
    public function getView($licenseId = null)
    {

        $license = License::find($licenseId);

        if (isset($license->id)) {

            if (!\App\Models\Company::isCurrentUserHasAccess($license)) {
                return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
            }
            return View::make('licenses/view', compact('license'));

        } else {
            // Prepare the error message
            $error = Lang::get('admin/licenses/message.does_not_exist', compact('id'));

            // Redirect to the user management page
            return Redirect::route('licenses')->with('error', $error);
        }
    }

    public function getClone($licenseId = null)
    {
         // Check if the license exists
        if (is_null($license_to_clone = License::find($licenseId))) {
            // Redirect to the blogs management page
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.does_not_exist'));
        } elseif (!Company::isCurrentUserHasAccess($license_to_clone)) {
            return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
        }

          // Show the page
        $license_options = array('0' => 'Top Level') + License::pluck('name', 'id')->toArray();
            $maintained_list = array('' => 'Maintained', '1' => 'Yes', '0' => 'No');
        $company_list = Helper::companyList();
        //clone the orig
        $license = clone $license_to_clone;
        $license->id = null;
        $license->serial = null;

        // Show the page
        $depreciation_list = Helper::depreciationList();
        $supplier_list = Helper::suppliersList();
        return View::make('licenses/edit')
        ->with('license_options', $license_options)
        ->with('depreciation_list', $depreciation_list)
        ->with('supplier_list', $supplier_list)
        ->with('license', $license)
        ->with('maintained_list', $maintained_list)
        ->with('company_list', $company_list);

    }


    /**
    *  Upload the file to the server
    *
    * @param  int  $licenseId
    * @return View
    **/
    public function postUpload($licenseId = null)
    {
        $license = License::find($licenseId);

        // the license is valid
        $destinationPath = config('app.private_uploads').'/licenses';

        if (isset($license->id)) {


            if (!Company::isCurrentUserHasAccess($license)) {
                return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
            }

            if (Input::hasFile('licensefile')) {

                foreach (Input::file('licensefile') as $file) {

                    $rules = array(
                    'licensefile' => 'required|mimes:png,gif,jpg,jpeg,doc,docx,pdf,txt,zip,rar|max:2000'
                    );
                    $validator = Validator::make(array('licensefile'=> $file), $rules);

                    if ($validator->passes()) {

                        $extension = $file->getClientOriginalExtension();
                        $filename = 'license-'.$license->id.'-'.str_random(8);
                        $filename .= '-'.str_slug($file->getClientOriginalName()).'.'.$extension;
                        $upload_success = $file->move($destinationPath, $filename);

                        //Log the deletion of seats to the log
                        $logaction = new Actionlog();
                        $logaction->asset_id = $license->id;
                        $logaction->asset_type = 'software';
                        $logaction->user_id = Auth::user()->id;
                        $logaction->note = e(Input::get('notes'));
                        $logaction->checkedout_to =  null;
                        $logaction->created_at =  date("Y-m-d h:i:s");
                        $logaction->filename =  $filename;
                        $log = $logaction->logaction('uploaded');
                    } else {
                         return Redirect::back()->with('error', Lang::get('admin/licenses/message.upload.invalidfiles'));
                    }


                }

                if ($upload_success) {
                    return Redirect::back()->with('success', Lang::get('admin/licenses/message.upload.success'));
                } else {
                    return Redirect::back()->with('success', Lang::get('admin/licenses/message.upload.error'));
                }

            } else {
                 return Redirect::back()->with('error', Lang::get('admin/licenses/message.upload.nofiles'));
            }





        } else {
            // Prepare the error message
            $error = Lang::get('admin/licenses/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('licenses')->with('error', $error);
        }
    }


    /**
    *  Delete the associated file
    *
    * @param  int  $licenseId
    * @param  int  $fileId
    * @return View
    **/
    public function getDeleteFile($licenseId = null, $fileId = null)
    {
        $license = License::find($licenseId);
        $destinationPath = config('app.private_uploads').'/licenses';

        // the license is valid
        if (isset($license->id)) {


            if (!Company::isCurrentUserHasAccess($license)) {
                return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
            }

            $log = Actionlog::find($fileId);
            $full_filename = $destinationPath.'/'.$log->filename;
            if (file_exists($full_filename)) {
                unlink($destinationPath.'/'.$log->filename);
            }
            $log->delete();
            return Redirect::back()->with('success', Lang::get('admin/licenses/message.deletefile.success'));

        } else {
            // Prepare the error message
            $error = Lang::get('admin/licenses/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('licenses')->with('error', $error);
        }
    }



    /**
    *  Display/download the uploaded file
    *
    * @param  int  $licenseId
    * @param  int  $fileId
    * @return View
    **/
    public function displayFile($licenseId = null, $fileId = null)
    {

        $license = License::find($licenseId);

        // the license is valid
        if (isset($license->id)) {

            if (!Company::isCurrentUserHasAccess($license)) {
                return Redirect::to('admin/licenses')->with('error', Lang::get('general.insufficient_permissions'));
            }

                $log = Actionlog::find($fileId);
                $file = $log->get_src();
                return Response::download($file);
        } else {
            // Prepare the error message
            $error = Lang::get('admin/licenses/message.does_not_exist', compact('id'));

            // Redirect to the licence management page
            return Redirect::route('licenses')->with('error', $error);
        }
    }

    public function getDatatable()
    {
        $licenses = License::with('company');

        if (Input::has('search')) {
            $licenses = $licenses->TextSearch(Input::get('search'));
        }

        $allowed_columns = ['id','name','purchase_cost','expiration_date','purchase_order','order_number','notes','purchase_date','serial'];
        $order = Input::get('order') === 'asc' ? 'asc' : 'desc';
        $sort = in_array(Input::get('sort'), $allowed_columns) ? e(Input::get('sort')) : 'created_at';

        $licenses = $licenses->orderBy($sort, $order);

        $licenseCount = $licenses->count();
        $licenses = $licenses->skip(Input::get('offset'))->take(Input::get('limit'))->get();

        $rows = array();

        foreach ($licenses as $license) {
            $actions = '<span style="white-space: nowrap;"><a href="'.route('freecheckout/license', $license->id).'" class="btn btn-primary btn-sm" style="margin-right:5px;" '.(($license->remaincount() > 0) ? '' : 'disabled').'>'.Lang::get('general.checkout').'</a> <a href="'.route('clone/license', $license->id).'" class="btn btn-info btn-sm" style="margin-right:5px;" title="Clone asset"><i class="fa fa-files-o"></i></a><a href="'.route('update/license', $license->id).'" class="btn btn-warning btn-sm" style="margin-right:5px;"><i class="fa fa-pencil icon-white"></i></a><a data-html="false" class="btn delete-asset btn-danger btn-sm" data-toggle="modal" href="'.route('delete/license', $license->id).'" data-content="'.Lang::get('admin/licenses/message.delete.confirm').'" data-title="'.Lang::get('general.delete').' '.htmlspecialchars($license->name).'?" onClick="return false;"><i class="fa fa-trash icon-white"></i></a></span>';

            $rows[] = array(
                'id'                => $license->id,
                'name'              => (string) link_to('/admin/licenses/'.$license->id.'/view', $license->name),
                'serial'            => (string) link_to('/admin/licenses/'.$license->id.'/view', mb_strimwidth($license->serial, 0, 50, "...")),
                'totalSeats'        => $license->totalSeatsByLicenseID(),
                'remaining'         => $license->remaincount(),
                'license_name'      => e($license->license_name),
                'license_email'      => e($license->license_email),
                'purchase_date'     => ($license->purchase_date) ? $license->purchase_date : '',
                'expiration_date'     => ($license->expiration_date) ? $license->expiration_date : '',
                'purchase_cost'     => ($license->purchase_cost) ? $license->purchase_cost : '',
                'purchase_order'     => ($license->purchase_order) ? e($license->purchase_order) : '',
                'order_number'     => ($license->order_number) ? e($license->order_number) : '',
                'notes'     => ($license->notes) ? e($license->notes) : '',
                'actions'           => $actions,
                'companyName'       => is_null($license->company) ? '' : e($license->company->name)
            );
        }

        $data = array('total' => $licenseCount, 'rows' => $rows);

        return $data;
    }

    public function getFreeLicense($licenseId)
    {
        // Check if the asset exists
        if (is_null($license = License::find($licenseId))) {
            // Redirect to the asset management page with error
            return Redirect::to('admin/licenses')->with('error', Lang::get('admin/licenses/message.not_found'));
        }
        $seatId = $license->freeSeat($licenseId);
        return Redirect::to('admin/licenses/'.$seatId.'/checkout');
    }
}