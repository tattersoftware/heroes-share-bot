<?php namespace App\Commands;

use App\Entities\Submission;
use App\Models\SubmissionModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Email\Email;
use Tatter\Outbox\Models\TemplateModel;
use Tatter\Pushover\Exceptions\PushoverException;

/**
 * Reddit Execute Task
 *
 * Loads submissions from the database
 * that have not yet been executed and
 * sends notifications from configured
 * handlers.
 */
class RedditExecute extends BaseCommand
{
	protected $group       = 'Tasks';
	protected $name        = 'reddit:execute';
	protected $description = 'Executes actions on filtered Reddit submissions.';
	protected $usage       = 'reddit:execute';

	public function run(array $params = [])
	{
		$notified = [];
		foreach (model(SubmissionModel::class)->where('executed', 0)->findAll() as $submission)
		{
			CLI::write('Sending notifications for ' . $submission->name);

			$this->email($submission);

			// Check if Pushoer is configured
			if (config('Pushover')->user)
			{
				$this->push($submission);
			}

			// Mark is as notified as we go in case tasks are run in parallel
			model(SubmissionModel::class)->update($submission->id, ['notified' => 1]);
		}
	}

	/**
	 * Sends an email based on $submission
	 *
	 * @param Submission $submission
	 */
	protected function email(Submission $submission): void
	{
		$template = model(TemplateModel::class)->findByName('Reddit Mention');

		// Prep Email to our Template
		$email = $template->email([
			'name'        => $submission->name,
			'title'       => 'Reddit Mention',
			'preview'     => $submission->excerpt,
			'contact'     => 'Heroes Share',
			'unsubscribe' => 'Reply with "Unsubscribe"',
			'author'      => $submission->author,
			'url'         => $submission->url,
			'match'       => $submission->match,
			'kind'        => $submission->kind,
			'html'        => $submission->html,
			'thumbnail'   => filter_var($submission->thumbnail, FILTER_VALIDATE_URL) === false
				? 'https://heroesshare.net/apple-touch-icon.png'
				: $submission->thumbnail,
		]);

		$email->setTo(config('Email')->recipients);

		if (! $email->send(false))
		{
			log_message('error', 'Unable to send an email: ' . $email->printDebugger());
		}
	}

	/**
	 * Sends a push notification to Pushover based on $submission
	 *
	 * @param Submission $submission
	 */
	protected function push(Submission $submission): void
	{
		$message = service('pushover')->message([
			'title'     => 'Reddit mention by ' . $submission->author,
			'message'   => $submission->excerpt,
			'html'      => 0,
			'url'       => $submission->url,
			'url_title' => $submission->title,
		]);

		// Try to download the thumbnail and use it as an attachment
		if (filter_var($submission->thumbnail, FILTER_VALIDATE_URL) !== false)
		{
			$thumbnail = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . basename($submission->thumbnail);

			if ($contents = file_get_contents($submission->thumbnail))
			{
				if (file_put_contents($thumbnail, $contents))
				{
					$message->attachment = $thumbnail;
				}
			}
		}

		try
		{
			$message->send();
		}
		catch (PushoverException $e)
		{
			log_message('error', 'Unable to send Pushover notification:');
			foreach (service('pushover')->getErrors() as $error)
			{
				log_message('error', $error);
			}
		}
	}
}
