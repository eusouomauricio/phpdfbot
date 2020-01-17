<?php

namespace App\Services\Collectors;

use App\Contracts\CollectInterface;
use App\Helpers\ExtractorHelper;
use App\Helpers\Helper;
use App\Models\Opportunity;
use App\Services\GmailService;
use Dacastro4\LaravelGmail\Exceptions\AuthException;
use Dacastro4\LaravelGmail\Services\Message\Attachment;
use Dacastro4\LaravelGmail\Services\Message\Mail;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JD\Cloudder\CloudinaryWrapper;
use JD\Cloudder\Facades\Cloudder;

/**
 * Class GMailMessages
 */
class GMailMessages implements CollectInterface
{

    /**
     * Gmail Labels
     */
    protected const LABEL_ENVIADO_PRO_BOT = 'Label_5391527689646879721';
    protected const LABEL_STILL_UNREAD = 'Label_3143736512522239870';

    /** @var Collection */
    private $opportunities = [];

    /** @var GmailService */
    private $gMailService;

    /**
     * GMailMessages constructor.
     * @param Collection $opportunities
     * @param GmailService $gMailService
     */
    public function __construct(Collection $opportunities, GmailService $gMailService)
    {
        $this->gMailService = $gMailService;
        $this->opportunities = $opportunities;
    }

    /**
     * Return the an array of messages, then remove messages from email
     *
     * @return Collection
     * @throws AuthException
     */
    public function collectOpportunities(): Collection
    {
        $messages = $this->fetchMessages();
        /** @var Mail $message */
        foreach ($messages as $message) {
            $this->createOpportunity($message);
            $message->markAsRead();
            $message->addLabel(self::LABEL_ENVIADO_PRO_BOT);
            $message->removeLabel(self::LABEL_STILL_UNREAD);
            $message->sendToTrash();
        }
        return $this->opportunities;
    }

    /**
     * @param Mail $message
     * @throws Exception
     */
    public function createOpportunity($message)
    {
        $title = $this->extractTitle($message);
        $description = $this->extractDescription($message);
        $this->opportunities->add(Opportunity::make(
            [
                Opportunity::TITLE => $title,
                Opportunity::DESCRIPTION => $description,
                Opportunity::FILES => $this->extractFiles($message),
                Opportunity::POSITION => $this->extractPosition($title),
                Opportunity::COMPANY => $this->extractCompany($title . $description),
                Opportunity::LOCATION => $this->extractLocation($title . $description),
                Opportunity::TAGS => $this->extractTags($title . $description),
                Opportunity::SALARY => $this->extractSalary($title . $description),
                Opportunity::URL => $this->extractUrl($message),
                Opportunity::ORIGIN => $this->extractOrigin($message),
                Opportunity::EMAILS => $this->extractEmails($message),
            ]
        ));
    }

    /**
     * Walks the GMail looking for specifics opportunity messages
     *
     * @return BaseCollection
     * @throws AuthException
     */
    protected function fetchMessages(): BaseCollection
    {
        $messageService = $this->gMailService->message();

        $words = '{' . implode(' ', array_map(function ($word) {
                return Str::contains($word, ' ') ? '"' . $word . '"' : $word;
            }, Config::get('constants.requiredWords'))) . '}';

        $messageService->add($words);

        $groups = array_keys(Config::get('constants.mailing'));
        $fromTo = [];
        foreach ($groups as $group) {
            $fromTo[] = 'list:' . $group;
            $fromTo[] = 'to:' . $group;
            $fromTo[] = 'bcc:' . $group;
        }

        $fromTo = '{' . implode(' ', $fromTo) . '}';

        $messageService->add($fromTo);
        $messageService->unread();

        $messages = $messageService->preload()->all();
        return $messages->reject(function (Mail $message) {
            return in_array($this->gMailService->user(), $message->getFrom(), true);
        });
    }

    /**
     * Get array of URL for attachments files
     *
     * @param Mail $message
     * @return array
     * @throws Exception
     */
    public function extractFiles($message): array
    {
        $files = [];
        if ($message->hasAttachments()) {
            $attachments = $message->getAttachments();
            /** @var Attachment $attachment */
            foreach ($attachments as $attachment) {
                if (!($attachment->getSize() < 50000
                    && strpos($attachment->getMimeType(), 'image') !== false)
                ) {
                    $extension = File::extension($attachment->getFileName());
                    $fileName = Helper::base64UrlEncode($attachment->getFileName()) . '.' . $extension;
                    $filePath = $attachment->saveAttachmentTo($message->getId() . '/', $fileName, 'uploads');
                    $filePath = Storage::disk('uploads')->path($filePath);
                    try {
                        list($width, $height) = getimagesize($filePath);
                        /** @var CloudinaryWrapper $cloudImage */
                        $cloudImage = Cloudder::upload($filePath, null);
                        $fileUrl = $cloudImage->secureShow(
                            $cloudImage->getPublicId(),
                            [
                                'width' => $width,
                                'height' => $height
                            ]
                        );
                        $files[] = $fileUrl;
                    } catch (Exception $exception) {
                        $this->error($exception->getMessage());
                    }
                }
            }
        }
        return $files;
    }

    /**
     * Get message body from GMail content
     *
     * @param Mail $message
     * @return bool|string
     */
    public function extractDescription($message): string
    {
        $htmlBody = $message->getHtmlBody();
        if (empty($htmlBody)) {
            $parts = $message->payload->getParts();
            if (count($parts)) {
                $parts = $parts[0]->getParts();
            }
            if (count($parts)) {
                $body = $parts[1]->getBody()->getData();
                $htmlBody = $message->getDecodedBody($body);
            }
        }
        return $htmlBody;
    }

    /**
     * @param Mail $message
     * @return string
     */
    public function extractOrigin($message): string
    {
        $to = $message->getTo();
        $to = array_map(function ($item) {
            return $item['email'];
        }, $to);
        return strtolower(json_encode($to));
    }

    /**
     * @param Mail $message
     * @return string
     */
    public function extractTitle($message): string
    {
        return $message->getSubject();
    }

    /**
     * @param $message
     * @return string
     */
    public function extractLocation($message): string
    {
        return implode(' / ', ExtractorHelper::extractLocation($message));
    }

    /**
     * @param $message
     * @return array
     */
    public function extractTags($message): array
    {
        return ExtractorHelper::extractTags($message);
    }

    /**
     * @param $message
     * @return string
     */
    public function extractUrl($message): string
    {
        $urls = ExtractorHelper::extractUrls($message);
        return implode(', ', $urls);
    }

    /**
     * @param $message
     * @return string
     */
    public function extractEmails($message): string
    {
        $mails = ExtractorHelper::extractEmail($message);
        return implode(', ', $mails);
    }

    /** @todo Match position */
    public function extractPosition($message): string
    {
        return '';
    }

    /** @todo Match salary */
    public function extractSalary($message): string
    {
        return '';
    }

    /** @todo Match company */
    public function extractCompany($message): string
    {
        return '';
    }
}