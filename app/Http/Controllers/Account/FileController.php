<?php

namespace App\Http\Controllers\Account;

use App\Category;
use App\File;
use App\Http\Requests\File\StoreFileRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\UpdateFileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;

class FileController extends Controller
{

	/**
	 * Show all files on files page.
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function index() {

		// Grab all the users files that are 'finished'
		$files = auth()->user()->files()->latest()->finished()->get();

		return view('account.files.index', compact('files'));
	}


	/**
	 * Show edit file form.
	 * @param File $file
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
	 */
	public function edit(File $file) {

		// Make sure the user owns the file before we store it in database.
		$this->authorize('touch', $file);

		$categories = Category::orderBy('name', 'asc')->get();

		$cats = DB::table('category_file')->where('file_id', '=', $file->id)->get();

		// Grab the latest approvals for the file being edited (if any),
		// to show on edit page what needs approving by admin.
		$approvals = $file->approvals->first();

		return view('account.files.edit', compact('file', 'approvals', 'categories', 'cats'));
	}

	/**
	 * Take user to the 'create' file page if they don't have one, and do authorization checking
	 * @param File $file
	 *
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
	 */
	public function create(File $file) {

		$categories = Category::orderBy('name', 'asc')->get();

		// If the file we passed in doesn't exist
		if (!$file->exists) {
			// Then create a 'skeletion' file,
			// and redirect where that files does exist.
			$file = $this->createAndReturnSkeletionFile();

			// Redirect to the files create page with the files we created above
			return redirect()->route('account.files.create', $file);
		}

		// Check if the file we are dealing with belongs to the currently signed in user.
		// Use the "authorize" method and pass in the method "touch" we are using inside
		// the 'app/Polices/FilePolicy' directory.
		$this->authorize('touch', $file);

		return view('account.files.create', compact('file', 'categories'));

	}


	/**
	 * Store the file in database
	 * @param File $file
	 * @param StoreFileRequest $request
	 *
	 * @return \Illuminate\Http\RedirectResponse
	 */
	public function store(File $file, StoreFileRequest $request) {

		// Make sure the user owns the file before we store it in database.
		$this->authorize('touch', $file);

		if (request('categories_id')) {
			$file->categories()->attach(request('categories_id'));
		}

		if(request('youtube_url')) {
			$url = $request->input( 'youtube_url' );

			if ( preg_match( '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url ) ) {
				$file->youtube_url = $url;
			} else {
				return redirect()->back()->withInput($request->input())->withError('Not valid Youtube URL');
			}
		}

		if(request('vimeo_url')) {
			$vimeoUrl = $request->input( 'vimeo_url' );

			if ( preg_match( '/\/\/(www\.)?vimeo.com\/(\d+)($|\/)/', $vimeoUrl ) ) {
				$file->vimeo_url = $vimeoUrl;
			} else {
				return redirect()->back()->withInput($request->input())->withError('Not valid Vimeo URL');
			}
		}

		// Update the fields that we 'only' need
		$file->fill($request->only(['avatar', 'title', 'overview', 'overview_short', 'price']));

		if (request('avatar')) {
			// Upload cover photo
			$this->uploadAvatar($request, $file);
		}

		// Set "finished" to true in database
		$file->finished = true;

		$file->save();

		return redirect()->route('account.files.index')
			->withSuccess('Your file has been submitted for review.');
	}


	/**
	 * Update a file, and check if it needs approval from admin (if any changes are made
	 * (excluding(price and live fields))
	 * @param File $file
	 * @param UpdateFileRequest $request
	 *
	 * @return mixed
	 */
	public function update(File $file, UpdateFileRequest $request) {

		// Make sure the user owns the file before we store it in database.
		$this->authorize('touch', $file);

		if (request('avatar')) {
			// Upload cover photo
			$this->uploadAvatar($request, $file);
			$file->save();
		}

		$file->categories()->sync(request('categories_id'));

		// If ONLY the 'price' OR/AND 'live' checkbox have been updated, then
		// update those two and redirect if we dont need approval.
		$file->update($request->only(['live', 'price']));

		// Data that needs checking for approval by admin.
		// ** Referencing APPROVAL_PROPERTIES constant in 'File' model
		$approvalProperties = $request->only(File::APPROVAL_PROPERTIES);

		// If the file needs approval, then we need to create a approval column in database
		if ($file->needsApproval($approvalProperties)) {

			if(request('youtube_url')) {
				$url = $request->input( 'youtube_url' );
				if (!preg_match( '%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url)) {
					// $matches[1] <--- to get ID
					return redirect()->back()->withInput($request->input())->withError('Not valid Youtube URL');
				}
			}

			if(request('vimeo_url')) {
				$vimeoUrl = $request->input( 'vimeo_url' );
				if (!preg_match( '/\/\/(www\.)?vimeo.com\/(\d+)($|\/)/', $vimeoUrl)) {
					// $matches[2] <--- to get ID
					return redirect()->back()->withInput($request->input())->withError('Not valid Vimeo URL');
				}
			}

			// Create the approvals column in table with data passed in
			// ** 'createApprovals' on File model
			$file->createApproval($approvalProperties);

			return back()->withSuccess('We will review your changes soon.');
		}

		return back()->withSuccess('File Updated');
	}


	/**
	 * Get the current user, and create a file with relationship
	 * @return mixed
	 */
	protected function createAndReturnSkeletionFile() {
		return auth()->user()->files()->create([
			'title' => 'Untitled',
			'overview_short' => 'None',
			'overview' => 'none',
			'price' => 0,
			'finished' => false
		]);
	}


	/**
	 * Upload the files cover photo.
	 * @param Request $request
	 * @param $file
	 */
	protected function uploadAvatar(Request $request, $file) {

		// Get the current file uploaded
		$avatar = request()->file('avatar');

		// Get the file name
		$avatarName = sha1($avatar->getClientOriginalName());

		// Get the file extension
		$avatarExtension = $avatar->getClientOriginalExtension();

		// Combine the image name and extension
		$image = "{$avatarName}.{$avatarExtension}";

		// Move the file to a path with the image name
		$request->file('avatar')->move(
			base_path() . '/public/images/files/cover/', $image
		);

		// Set the files avatar to the image
		$file->avatar = $image;
	}
}
