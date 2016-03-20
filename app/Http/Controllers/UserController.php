<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use Hash;
use App\User;
use App\Bioreactor;
use Carbon\Carbon;
use DB;
use Excel;

use App\Http\Requests;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
	/**
	 * Show all users. Only available if the logged in user
	 * is an admin
	 *
	 *
	 */
	public function index() {	

		// get all the users to show

		$users = User::all();

		//dd($users->toJson());

	    return view('User.index', ['route'			=> 'users',
		                             'header_title'		=> 'All Users',
									 'dbdata'			=> $users
									]);	
	}

	/**
	 * Download all users as Excel spreadsheet. Only available if the logged in user
	 * is an admin
	 *
	 *
	 */
	public function excel() {	

		// get all the users to show

		//$users = User::all();
		//dd($users);

		$users = User::select('name', 'email', 'deviceid', 'isadmin', 'created_at', 'updated_at')->get();

		Excel::create('users', function($excel) use ($users) {

			// Set the title
			$excel->setTitle('User List');

			// Chain the setters
			$excel->setCreator('Solar BioCells')
					->setCompany('Solar BioCells');

			// Call them separately
			$excel->setDescription('List of users registered for Bioreactor login');


			$excel->sheet('User List', function ($sheet) use ($users) {
				$sheet->row(1, array('Name', 'Email', 'BioReactor ID', 'Admin?','Created On', 'Last Updated'));

				$sheet->fromArray($users, null, 'A2', false, false);
			});

		})->export('xls');

	}

	/**
	 * Show a single users record for editing
	 *
	 * @param int $id The numeric id of the user
	 *
	 */
	public function show($id)
    {

		// load the record from the table
		try {
			$user = User::where('id', '=', $id)->firstOrFail();
		}
		catch (\Exception $e) {
			$message = 'Sorry! Invalid id';
			dd($message);
			//return Redirect::to('error')->with('message', $message);
		}
		//dd($user);

		$bioreactors = Bioreactor::select('deviceid', DB::raw("coalesce(deviceid,'')||' '||coalesce(name,'') as wholename"))->orderBy('wholename')->lists('wholename', 'deviceid');

	    return view('User.user', [	'route'				=> 'user',
 									'header_title'		=> 'Edit User',
									'user'				=> $user,
									'bioreactors'		=> $bioreactors
								]);	
    }

	/**
	 * Delete a single users record
	 *
	 * @param int $id The numeric id of the user
	 *
	 */
	public function delete($id)
    {

		// load the record from the table
		try {
			$user = User::where('id', '=', $id)->firstOrFail();
		}
		catch (\Exception $e) {
			$message = 'Sorry! Invalid id';
			dd($message);
			//return Redirect::to('error')->with('message', $message);
		}
		
		//dd($user);

		$user->delete();

		// finish by sending the user back to the list of all users
		return redirect('/users');
	}


	/**
	 * Show the editing form for a new user
	 *
	 *
	 *
	 */
	public function create()
    {
		// get the record of the logged in user
		// and make sure they are an admin

		if ( !Auth::user()->isadmin)
		{
			$message = 'Sorry! You are NOT an admin and cannot add users';
			dd($message);
		}


		$user = new User();

		// setup the default password for a new user
		$user->setDefaultPassword();

		$bioreactors = Bioreactor::select('deviceid', DB::raw("coalesce(deviceid,'')||' '||coalesce(name,'') as wholename"))->orderBy('wholename')->lists('wholename', 'deviceid');

	    return view('User.user', [	'route'				=> 'user',
 									'header_title'		=> 'Add User',
									'user'				=> $user,
									'bioreactors'		=> $bioreactors
								]);	
    }

	/**
	 * Process a post from editing a user or creating a new 
	 *  user.
	 *
	 * @param Request $request the posted data 
	 */
    public function update(Request $request)
    {
		// the id will be non-empty for editing an existing user.
		//
		if ( $request->id !="")	{

			// load the record from the table
			try {
				$user = User::where('id', '=', $request->id)->firstOrFail();
			}
			catch (\Exception $e) {
				$message = 'Sorry! Invalid id';
				dd($message);
				//return Redirect::to('error')->with('message', $message);
			}
		}
		else { // a new user
			$user = new User();
			// hash the new user password for saving
			$user->password = Hash::make($request->password);
		}

		// set the common data updates
		$user->name = $request->name;
		$user->email = $request->email;
		$user->deviceid = $request->deviceid;

		// since isadmin is a checkbox, it won't be passed if it wasn't 
		// checked off
		if ($request->isadmin===null)
			$user->isadmin='0';
		else
			$user->isadmin='1';

		// set the last updated date to now
		$user->updated_at = Carbon::now();

		//dd($user);

		$user->save();

		// finish by sending the user back to the list of all users
		return redirect('/users');
	}
}