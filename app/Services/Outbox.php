<?php

namespace App\Services;

use App\Models\Delivery;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class Outbox
{
    public function send(int $id): string
    {
        // Claim before contacting SMTP. A concurrent runner can only claim pending rows.
        $claimed = Delivery::whereKey($id)->where('status', 'pending')->update([
            'status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'last_error' => null,
        ]);
        if (!$claimed) {
            return 'skipped';
        }

        $delivery = Delivery::findOrFail($id);
        $payload = $delivery->payload;
        $submitted = false;
        try {
            $attachmentIds = array_unique($payload['attachments'] ?? []);
            $files = $delivery->submission->attachments()->whereKey($attachmentIds)->get();
            if ($files->count() !== count($attachmentIds)) {
                throw new RuntimeException('Unknown attachment');
            }
            foreach ($files as $file) {
                if (!Storage::disk('local')->exists($file->path)) {
                    throw new RuntimeException('Missing attachment');
                }
            }
            $sent = Mail::raw($payload['body'], function (Message $message) use ($payload, $files, &$submitted) {
                if (empty($payload['to'])) {
                    throw new RuntimeException('Missing recipient');
                }
                $message->to($payload['to'])->subject($payload['subject']);
                if (!empty($payload['cc'])) {
                    $message->cc($payload['cc']);
                }
                if (!empty($payload['bcc'])) {
                    $message->bcc($payload['bcc']);
                }
                if (!empty($payload['reply_to'])) {
                    $message->replyTo($payload['reply_to']);
                }
                foreach ($files as $file) {
                    $message->attach(Storage::disk('local')->path($file->path), [
                        'as' => $file->name, 'mime' => $file->mime,
                    ]);
                }
                $submitted = true;
            });
            if ($sent === null) {
                throw new RuntimeException('Mail transport did not confirm sending');
            }
        } catch (Throwable $error) {
            // A transport failure after handing over the message can mean SMTP actually accepted it.
            // Keep addresses, message bodies and server credentials out of error logs.
            $status = $submitted ? 'unknown' : 'failed';
            $delivery->update(['status' => $status, 'last_error' => class_basename($error)]);
            return $status;
        }
        $delivery->update(['status' => 'sent', 'payload' => [], 'last_error' => null]);
        return 'sent';
    }
}
