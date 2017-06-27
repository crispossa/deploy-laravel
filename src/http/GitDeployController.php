<?php

namespace CrisPossa\GitDeploy\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
// use App\Http\Requests;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class GitDeployController extends Controller
{
	public function gitHook(Request $request)
	{


		// create a log channel
		$log = new Logger('gitdeploy');
		$log->pushHandler(new StreamHandler(storage_path('logs/gitdeploy.log'), Logger::WARNING));


		$git_path = !empty(config('gitdeploy.git_path')) ? config('gitdeploy.git_path') : 'git';
		$git_remote = !empty(config('gitdeploy.remote')) ? config('gitdeploy.remote') : 'origin';

		// Limit to known servers
		if (!empty(config('gitdeploy.allowed_sources')) && !in_array($_SERVER['REMOTE_ADDR'], config('gitdeploy.allowed_sources'))) {
			$log->addError('Request must come from an approved IP');
			return Response::json([
				'success' => false,
				'message' => 'Request must come from an approved IP',
			], 500);
		}

		// Collect the posted data
		$postdata = json_decode($request->getContent(), TRUE);
		if (empty($postdata)) {
			$log->addError('Web hook data does not look valid');
			return Response::json([
				'success' => false,
				'message' => 'Web hook data does not look valid',
			], 500);
		}

		// Check the config's directory
		$repo_dir = config('gitdeploy.repo_path');
		if (!empty($repo_dir) && !file_exists($repo_dir.'/.git/config')) {
			$log->addError('Invalid repo path in config');
			return Response::json([
				'success' => false,
				'message' => 'Invalid repo path in config',
			], 500);
		}

		// Try to determine Laravel's directory going up paths until we find a .env
		if (empty($repo_dir)) {
			$checked[] = $repo_dir;
			$repo_dir = __DIR__;
			do {
				$repo_dir = dirname($repo_dir);
			} while ($repo_dir !== '/' && !file_exists($repo_dir.'/.env'));
		}

		// This is not necessarily the repo's root so go up more paths if necessary
		if ($repo_dir !== '/') {
			while ($repo_dir !== '/' && !file_exists($repo_dir.'/.git/config')) {
				$repo_dir = dirname($repo_dir);
			}
		}

		// So, do we have something valid?
		if ($repo_dir === '/' || !file_exists($repo_dir.'/.git/config')) {
			$log->addError('Could not determine the repo path');
			return Response::json([
				'success' => false,
				'message' => 'Could not determine the repo path',
			], 500);
		}

		// Get current branch this repository is on
		$cmd = escapeshellcmd($git_path) . ' --git-dir=' . escapeshellarg($repo_dir . '/.git') .  ' --work-tree=' . escapeshellarg($repo_dir) . ' rev-parse --abbrev-ref HEAD';
		$current_branch = trim(shell_exec($cmd));

		// Get branch this webhook is for
		$pushed_branch = explode('/', $postdata['ref']);
		$pushed_branch = trim($pushed_branch[2]);

		// If the refs don't matchthis branch, then no need to do a git pull
		if ($current_branch !== $pushed_branch){
			$log->addWarning('Pushed refs do not match current branch');
			return Response::json([
				'success' => false,
				'message' => 'Pushed refs do not match current branch',
			], 500);
		}

		// git pull
		$cmd = escapeshellcmd($git_path) . ' --git-dir=' . escapeshellarg($repo_dir . '/.git') . ' --work-tree=' . escapeshellarg($repo_dir) . ' pull ' . escapeshellarg($git_remote) . ' ' . escapeshellarg($current_branch) . ' > ' . escapeshellarg($repo_dir . '/storage/logs/gitdeploy.log');

		$server_response = [
			'cmd' => $cmd,
			'user' => shell_exec('whoami'),
			'response' => shell_exec($cmd),
		];


		if (!empty(config('gitdeploy.email_recipients'))) {

			// Humanise the commit log
			foreach ($postdata['commits'] as $commit_key => $commit) {

				// Split message into subject + description (Assumes Git's recommended standard where first line is the main summary)
				$subject = strtok($commit['message'], "\n");
				$description = '';

				// Beautify date
				$date = new \DateTime($commit['timestamp']);
				$date_str = $date->format('d/m/Y, g:ia');

				$postdata['commits'][$commit_key]['human_id'] = substr($commit['id'], 0, 9);
				$postdata['commits'][$commit_key]['human_subject'] = $subject;
				$postdata['commits'][$commit_key]['human_description'] = $description;
				$postdata['commits'][$commit_key]['human_date'] = $date_str;
			}

			// Use package's own sender or the project default?
			$addressdata['sender_name'] = config('mail.from.name');
			$addressdata['sender_address'] = config('mail.from.address');
			if (config('gitdeploy.email_sender.address') !== null) {
				$addressdata['sender_name'] = config('gitdeploy.email_sender.name');
				$addressdata['sender_address'] = config('gitdeploy.email_sender.address');
			}

			// Recipients
			$addressdata['recipients'] = config('gitdeploy.email_recipients');

			\Mail::send('gitdeploy::email', [ 'server' => $server_response, 'git' => $postdata ], function($message) use ($postdata, $addressdata) {
				$message->from($addressdata['sender_address'], $addressdata['sender_name']);
				foreach ($addressdata['recipients'] as $recipient) {
					$message->to($recipient['address'], $recipient['name']);
				}
				$message->subject('Repo: ' . $postdata['repository']['name'] . ' updated');
			});

		}

		return Response::json(true);

	}
}
