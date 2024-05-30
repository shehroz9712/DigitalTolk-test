<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);

        if (!$cuser) {
            return ['emergencyJobs' => [], 'noramlJobs' => [], 'cuser' => null, 'usertype' => ''];
        }

        $usertype = $cuser->is('customer') ? 'customer' : ($cuser->is('translator') ? 'translator' : '');

        $emergencyJobs = [];
        $normalJobs = [];

        if ($usertype === 'customer') {
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            foreach ($jobs as $job) {
                $this->organizeJobs($job, $emergencyJobs, $normalJobs);
            }
        } elseif ($usertype === 'translator') {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();

            foreach ($jobs as $job) {
                $this->organizeJobs($job, $emergencyJobs, $normalJobs);
            }
        }

        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }

    private function organizeJobs($jobitem, &$emergencyJobs, &$normalJobs)
    {
        if ($jobitem->immediate == 'yes') {
            $emergencyJobs[] = $jobitem;
        } else {
            $normalJobs[] = $jobitem;
        }
    }
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);

        if (!$cuser) {
            return [
                'emergencyJobs' => [],
                'normalJobs' => [],
                'jobs' => null,
                'cuser' => null,
                'usertype' => '',
                'numpages' => 0,
                'pagenum' => 0
            ];
        }

        $usertype = $cuser->is('customer') ? 'customer' : ($cuser->is('translator') ? 'translator' : '');

        $emergencyJobs = [];
        $normalJobs = [];

        if ($usertype === 'customer') {
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => [],
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0
            ];
        } elseif ($usertype === 'translator') {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $jobs = $jobs_ids;

            return [
                'emergencyJobs' => $emergencyJobs,
                'normalJobs' => $jobs_ids,
                'jobs' => $jobs,
                'cuser' => $cuser,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $page
            ];
        }
    }

    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;

        if ($user->user_type !== env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => "Translator cannot create a booking"
            ];
        }

        if (!isset($data['from_language_id'])) {
            return [
                'status' => 'fail',
                'message' => "You must fill in all fields",
                'field_name' => "from_language_id"
            ];
        }

        if ($data['immediate'] === 'no') {
            $requiredFields = ['due_date', 'due_time', 'duration'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'status' => 'fail',
                        'message' => "You must fill in all fields",
                        'field_name' => $field
                    ];
                }
            }
        } elseif (empty($data['duration'])) {
            return [
                'status' => 'fail',
                'message' => "You must fill in all fields",
                'field_name' => "duration"
            ];
        }

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        if ($data['immediate'] === 'yes') {
            $due_carbon = Carbon::now()->addMinute($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');

            if ($due_carbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past"
                ];
            }
        }

        // Handle job_for, gender, and certified fields

        // Set job_type based on consumer_type

        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $user->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        // Prepare response data

        return $response;
    }
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address']) && $data['address'] !== '') {
            $userMeta = $job->user->userMeta;
            $job->address = $data['address'];
            $job->instructions = $data['instructions'] ?? $userMeta->instructions;
            $job->town = $data['town'] ?? $userMeta->city;
        }

        $job->save();

        $email = $job->user_email ?? $job->user->email;
        $name = $job->user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data = [
            'user' => $job->user,
            'job'  => $job
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $data['user_type'];
        $response['job'] = $job;
        $response['status'] = 'success';

        $data = $this->jobToData($job);

        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    public function jobToData($job)
    {
        $data = [];
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];
        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if (in_array($job->certified, ['n_health', 'n_law'])) {
                $data['job_for'][] = ucfirst(substr($job->certified, 2)) . 'tolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    public function jobEnd($post_data = [])
    {
        $completeddate = now()->format('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job = Job::with('translatorJobRel')->find($jobid);

        $start = date_create($job->due);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');

        $job->end_at = $completeddate;
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user;
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];

        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $translator = $tr->user;
        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user'         => $translator,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];

        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }


    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = $this->getJobType($translator_type);

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $gender, $translator_level);

        $job_ids = $this->filterJobsByTown($user_id, $job_ids);

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    private function getJobType($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
                return 'unpaid';
            default:
                return 'unpaid';
        }
    }

    private function filterJobsByTown($user_id, $job_ids)
    {
        return $job_ids->reject(function ($job) use ($user_id) {
            $job = Job::find($job->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);
            return ($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' && $checktown == false;
        });
    }

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')->where('status', '1')->where('id', '!=', $exclude_user_id)->get();
        $translator_array = [];
        $delpay_translator_array = [];

        foreach ($users as $oneUser) {
            if (!$this->isNeedToSendPush($oneUser->id)) continue;
            $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
            if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);
            foreach ($jobs as $oneJob) {
                if ($job->id == $oneJob->id) {
                    $userId = $oneUser->id;
                    $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                    if ($job_for_translator == 'SpecificJob') {
                        $job_checker = Job::checkParticularJob($userId, $oneJob);
                        if ($job_checker != 'userCanNotAcceptJob') {
                            $target_array = $this->isNeedToDelayPush($oneUser->id) ? $delpay_translator_array : $translator_array;
                            $target_array[] = $oneUser;
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msg_contents = $data['immediate'] == 'no' ? 'Ny bokning för ' : 'Ny akutbokning för ';
        $msg_contents .= $data['language'] . 'tolk ' . $data['duration'] . 'min' . ($data['immediate'] == 'no' ? ' ' . $data['due'] : '');

        $msg_text = ["en" => $msg_contents];

        $this->logPushInfo($job->id, $translator_array, $delpay_translator_array, $msg_text, $data);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true);
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $phoneJobMessageTemplate = $this->getPhoneJobMessageTemplate($job, $jobPosterMeta);
        $physicalJobMessageTemplate = $this->getPhysicalJobMessageTemplate($job, $jobPosterMeta);

        $message = $this->decideMessageBasedOnJobType($job, $phoneJobMessageTemplate, $physicalJobMessageTemplate);

        $this->sendMessagesToTranslators($translators, $message);

        return count($translators);
    }

    private function getPhoneJobMessageTemplate($job, $jobPosterMeta)
    {
        return trans('sms.phone_job', [
            'date' => date('d.m.Y', strtotime($job->due)),
            'time' => date('H:i', strtotime($job->due)),
            'duration' => $this->convertToHoursMins($job->duration),
            'jobId' => $job->id
        ]);
    }

    private function getPhysicalJobMessageTemplate($job, $jobPosterMeta)
    {
        return trans('sms.physical_job', [
            'date' => date('d.m.Y', strtotime($job->due)),
            'time' => date('H:i', strtotime($job->due)),
            'town' => $job->city ? $job->city : $jobPosterMeta->city,
            'duration' => $this->convertToHoursMins($job->duration),
            'jobId' => $job->id
        ]);
    }

    private function decideMessageBasedOnJobType($job, $phoneJobMessageTemplate, $physicalJobMessageTemplate)
    {
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            return $physicalJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            return $phoneJobMessageTemplate;
        } else {
            return $phoneJobMessageTemplate; // Default to phone job
        }
    }

    private function sendMessagesToTranslators($translators, $message)
    {
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }
    }

    public function isNeedToDelayPush($user_id)
    {
        return DateTimeHelper::isNightTime() && TeHelper::getUsermeta($user_id, 'not_get_nighttime') == 'yes';
    }

    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') != 'yes';
    }

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = env('APP_ENV') == 'prod' ? config('app.prodOnesignalAppID') : config('app.devOnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", env('APP_ENV') == 'prod' ? config('app.prodOnesignalApiKey') : config('app.devOnesignalApiKey'));

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];
        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['session_time'] == '') return false;
            $interval = $data['session_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            $this->sendSessionEndNotification($job, $user, $session_time, 'faktura');
        }
        $job->save();
        return true;
    }

    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if ($data['status'] == 'assigned' && $changedTranslator) {
            $this->sendAssignedNotification($job, $user);
            return true;
        } else {
            $this->sendCancellationNotification($job, $user);
            return true;
        }
    }

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = [];
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        $msg_text = $this->getSessionStartMessage($job, $language, $due_explode, $duration);

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->sendPushForSessionStartRemind($user, $job->id, $data, $msg_text);
        }
    }

    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    private function sendSessionEndNotification($job, $user, $session_time, $for_text)
    {
        $email = $this->getRecipientEmail($job, $user);
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => $for_text
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    private function getRecipientEmail($job, $user)
    {
        return !empty($job->user_email) ? $job->user_email : $user->email;
    }

    private function getSessionStartMessage($job, $language, $due_explode, $duration)
    {
        if ($job->customer_physical_type == 'yes') {
            return ["en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'];
        } else {
            return ["en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'];
        }
    }

    private function sendPushForSessionStartRemind($user, $job_id, $data, $msg_text)
    {
        $users_array = array($user);
        $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job_id]);
    }

    private function sendAssignedNotification($job, $user)
    {
        $job->save();
        $job_data = $this->jobToData($job);
        $email = $this->getRecipientEmail($job, $user);
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);
        $this->sendTranslatorChangeNotification($job, $user);
        $this->sendSessionStartRemindNotification($user, $job, TeHelper::fetchLanguageFromJobId($job->from_language_id), $job->due, $job->duration);
    }

    private function sendTranslatorChangeNotification($job, $user)
    {
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $email = $translator->email;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $dataEmail = [
            'user' => $translator,
            'job'  => $job
        ];
        $this->mailer->send($email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
    }
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->sendCancellationNotification($job);
                $this->sendTranslatorCancellationNotification($job);
            }
            $job->save();
            return true;
        }
        return false;
    }

    private function sendCancellationNotification($job)
    {
        $user = $job->user()->first();
        $email = $this->getRecipientEmail($job, $user);
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    }

    private function sendTranslatorCancellationNotification($job)
    {
        $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $email = $user->user->email;
        $name = $user->user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if ($this->translatorChangeRequested($current_translator, $data)) {
            $log_data = [];
            if ($this->translatorNeedsToBeChanged($current_translator, $data)) {
                $new_translator = $this->createNewTranslator($current_translator, $data, $job);
                $this->cancelCurrentTranslator($current_translator);
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif ($this->translatorNeedsToBeAssigned($current_translator, $data)) {
                $new_translator = $this->assignNewTranslator($data, $job);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function translatorChangeRequested($current_translator, $data)
    {
        return !is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '';
    }

    private function translatorNeedsToBeChanged($current_translator, $data)
    {
        return !is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0);
    }

    private function translatorNeedsToBeAssigned($current_translator, $data)
    {
        return is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '');
    }

    private function createNewTranslator($current_translator, $data, $job)
    {
        if ($data['translator_email'] != '') {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = $current_translator->to[];
        $new_translator['user_id'] = $data['translator'];
        unset($new_translator['id']);
        return Translator::create($new_translator);
    }

    private function cancelCurrentTranslator($current_translator)
    {
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
    }

    private function assignNewTranslator($data, $job)
    {
        if ($data['translator_email'] != '') {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        return Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $this->getUserForJob($job);
        $email = $this->getEmailForUser($user, $job);
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $this->getUserForJob($job);
        $email = $this->getEmailForUser($user, $job);
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired',
            'language'          => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration'          => $job->duration,
            'due'               => $job->due
        ];

        $msg_text = [
            "en" => "Tyvärr har ingen tolk accepterat er bokning: ({$data['language']}, {$data['duration']} min, {$data['due']}). Vänligen pröva boka om tiden."
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    private function getUserForJob($job)
    {
        $user = $job->user()->first();
        return !empty($job->user_email) ? $user : $user->email;
    }

    private function getEmailForUser($user, $job)
    {
        return !empty($job->user_email) ? $job->user_email : $user->email;
    }
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();
        $data = $this->prepareJobDataForNotification($job, $user_meta);
        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function prepareJobDataForNotification($job, $user_meta)
    {
        $data = [
            'job_id'                 => $job->id,
            'from_language_id'       => $job->from_language_id,
            'immediate'              => $job->immediate,
            'duration'               => $job->duration,
            'status'                 => $job->status,
            'gender'                 => $job->gender,
            'certified'              => $job->certified,
            'due'                    => $job->due,
            'job_type'               => $job->job_type,
            'customer_phone_type'    => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'          => $user_meta->city,
            'customer_type'          => $user_meta->customer_type,
        ];

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];
        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = [];

        if ($job->gender != null) {
            $data['job_for'][] = ucfirst($job->gender);
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $msg_text = [
            "en" => 'Du har nu fått ' . ($job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen') . " för $language kl $duration den $due. Vänligen säkerställ att du är förberedd för den tiden. Tack!"
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->bookingRepository->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";

        $first = true;

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }

            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }

        $user_tags .= ']';

        return $user_tags;
    }

    public function acceptJob($data, $user)
    {
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        $response = $this->processAcceptJob($job, $user);

        return $response;
    }

    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = $this->processAcceptJob($job, $cuser);

        return $response;
    }

    private function processAcceptJob($job, $user)
    {
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job->id, $user->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job->id)) {
                $this->updateJobStatus($job, 'assigned');

                $this->sendJobAcceptedConfirmationEmail($job);

                $this->sendJobAcceptedNotification($job, $user);

                $jobs = $this->getPotentialJobs($user);

                $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    private function updateJobStatus($job, $status)
    {
        $job->status = $status;
        $job->save();
    }

    private function sendJobAcceptedConfirmationEmail($job)
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }


    private function sendJobAcceptedNotification($job, $user)
    {
        // Send email confirmation
        $this->sendJobAcceptedConfirmationEmail($job);

        // Prepare data for push notification
        $data = [
            'notification_type' => 'job_accepted',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];

        $msg_text = [
            "en" => 'Din bokning för ' . $data['language'] . ' tolkning, ' . $data['duration'] . ' min, ' . $data['due'] . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];

        // Send push notification if needed
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }
    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $this->handleCustomerCancellation($job, $translator, $response);
        } else {
            $this->handleTranslatorCancellation($job, $cuser, $response);
        }

        return $response;
    }

    private function handleCustomerCancellation($job, $translator, &$response)
    {
        $job->withdraw_at = Carbon::now();
        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }
        $job->save();
        Event::fire(new JobWasCanceled($job));
        $response['status'] = 'success';
        $response['jobstatus'] = 'success';

        if ($translator) {
            $this->sendJobCancelledNotificationToTranslator($job, $translator);
        }
    }

    private function sendJobCancelledNotificationToTranslator($job, $translator)
    {
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];

        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $data['language'] . ' tolk, ' . $data['duration'] . 'min, ' . $data['due'] . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    private function handleTranslatorCancellation($job, $cuser, &$response)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $this->rescheduleJob($job, $cuser);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }
    }

    private function rescheduleJob($job, $cuser)
    {
        $customer = $job->user()->first();

        if ($customer) {
            $this->sendJobCancelledNotificationToCustomer($job, $customer);
        }

        $job->status = 'pending';
        $job->created_at = date('Y-m-d H:i:s');
        $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
        $job->save();

        $translator = Job::deleteTranslatorJobRel($translator->id, $job->id);

        $data = $this->jobToData($job);
        $this->sendNotificationTranslator($job, $data, $translator->id);
    }

    private function sendJobCancelledNotificationToCustomer($job, $customer)
    {
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'duration' => $job->duration,
            'due' => $job->due
        ];

        $msg_text = [
            "en" => 'Er ' . $data['language'] . ' tolkning ' . $data['duration'] . 'min ' . $data['due'] . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        ];

        if ($this->isNeedToSendPush($customer->id)) {
            $users_array = [$customer];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
        }
    }

    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = $this->getJobType($cuser_meta->translator_type);
        $userlanguage = $this->getUserLanguages($cuser);
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        return $this->filterPotentialJobs($job_ids, $cuser);
    }

    private function getJobType($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
                return 'unpaid';
            default:
                return 'unpaid';
        }
    }

    private function getUserLanguages($cuser)
    {
        return UserLanguages::where('user_id', '=', $cuser->id)->pluck('lang_id')->all();
    }

    private function filterPotentialJobs($job_ids, $cuser)
    {
        return $job_ids->reject(function ($job) use ($cuser) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                return true;
            }

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown) {
                return true;
            }

            return false;
        });
    }
    public function endJob($post_data)
    {
        $job = $this->findJobById($post_data["job_id"]);

        if ($job->status != 'started') {
            return ['status' => 'success'];
        }

        $interval = $this->calculateSessionTime($job->due);

        $this->updateJobStatus($job, 'completed', $interval);

        $this->sendSessionEmail($job, 'faktura');

        $tr = $this->getTranslatorJob($job);

        $this->sendSessionEmail($job, 'lön', $tr);

        $this->updateTranslatorJob($tr, $post_data['user_id']);

        return ['status' => 'success'];
    }

    public function customerNotCall($post_data)
    {
        $job = $this->findJobById($post_data["job_id"]);

        $interval = $this->calculateSessionTime($job->due);

        $this->updateJobStatus($job, 'not_carried_out_customer');

        $tr = $this->getTranslatorJob($job);

        $this->updateTranslatorJob($tr, $tr->user_id);

        return ['status' => 'success'];
    }

    private function findJobById($jobId)
    {
        return Job::with('translatorJobRel')->findOrFail($jobId);
    }

    private function calculateSessionTime($dueDate)
    {
        $start = date_create($dueDate);
        $end = date_create(date('Y-m-d H:i:s'));
        $diff = date_diff($end, $start);
        return $diff->format('%h:%i:%s');
    }

    private function updateJobStatus($job, $status, $interval = null)
    {
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = $status;
        $job->session_time = $interval;
        $job->save();
    }

    private function sendSessionEmail($job, $forText, $user = null)
    {
        $user = $user ?: $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => $forText
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    private function getTranslatorJob($job)
    {
        return $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
    }

    private function updateTranslatorJob($tr, $completedBy)
    {
        $tr->completed_at = date('Y-m-d H:i:s');
        $tr->completed_by = $completedBy;
        $tr->save();
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = $this->applySuperadminFilters($allJobs, $requestdata, $limit);
        } else {
            $allJobs = $this->applyRegularUserFilters($allJobs, $consumer_type, $requestdata, $limit);
        }

        $allJobs->orderBy('created_at', 'desc')
            ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }

        return $allJobs;
    }

    protected function applySuperadminFilters($query, $requestdata, $limit)
    {
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            if (is_array($requestdata['id'])) {
                $query->whereIn('id', $requestdata['id']);
            } else {
                $query->where('id', $requestdata['id']);
            }
            $requestdata = array_only($requestdata, ['id']);
        }

        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $query->count()];
        }

        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            if (is_array($requestdata['id']))
                $query->whereIn('id', $requestdata['id']);
            else
                $query->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $query->whereIn('status', $requestdata['status']);
        }
        if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
            $query->where('expired_at', '>=', $requestdata['expired_at']);
        }
        if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
            $query->where('will_expire_at', '>=', $requestdata['will_expire_at']);
        }
        if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
            if ($users) {
                $query->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }
        if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
            if ($users) {
                $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                $query->whereIn('id', $allJobIDs);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('created_at', '<=', $to);
            }
            $query->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('due', '<=', $to);
            }
            $query->orderBy('due', 'desc');
        }

        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $query->whereIn('job_type', $requestdata['job_type']);
        }

        if (isset($requestdata['physical'])) {
            $query->where('customer_physical_type', $requestdata['physical']);
            $query->where('ignore_physical', 0);
        }

        if (isset($requestdata['phone'])) {
            $query->where('customer_phone_type', $requestdata['phone']);
            if (isset($requestdata['physical']))
                $query->where('ignore_physical_phone', 0);
        }

        if (isset($requestdata['flagged'])) {
            $query->where('flagged', $requestdata['flagged']);
            $query->where('ignore_flagged', 0);
        }

        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
            $query->whereDoesntHave('distance');
        }

        if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }

        if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
            $query = $query->count();

            return ['count' => $query];
        }

        if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
            $query->whereHas('user.userMeta', function ($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }

        if (isset($requestdata['booking_type'])) {
            if ($requestdata['booking_type'] == 'physical')
                $query->where('customer_physical_type', 'yes');
            if ($requestdata['booking_type'] == 'phone')
                $query->where('customer_phone_type', 'yes');
        }
        $query->orderBy('created_at', 'desc');
        $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all')
            $query = $query->get();
        else
            $query = $query->paginate(15);
        return $query;
    }

    protected function applyRegularUserFilters($query, $consumer_type, $requestdata, $limit)
    {
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                return ['count' => $query->count()];
            }
        }

        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $query->where('id', $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }

        if ($consumer_type == 'RWS') {
            $query->where('job_type', '=', 'rws');
        } else {
            $query->where('job_type', '=', 'unpaid');
        }
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $query->count()];
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $query->whereIn('from_language_id', $requestdata['lang']);
        }
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $query->whereIn('status', $requestdata['status']);
        }
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $query->whereIn('job_type', $requestdata['job_type']);
        }
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $query->where('user_id', '=', $user->id);
            }
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('created_at', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('created_at', '<=', $to);
            }
            $query->orderBy('created_at', 'desc');
        }
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $query->where('due', '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $query->where('due', '<=', $to);
            }
            $query->orderBy('due', 'desc');
        }

        $query->orderBy('created_at', 'desc');
        $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all')
            $query = $query->get();
        else
            $query = $query->paginate(15);

        return $query;
    }


    public function alerts()
    {
        $sesJobs = [];
        $jobId = [];

        // Filter jobs that meet session time conditions
        foreach (Job::all() as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($diff >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        // Extract job IDs
        foreach ($sesJobs as $job) {
            $jobId[] = $job->id;
        }

        // Get languages and request data
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email')->toArray();
        $all_translators = User::where('user_type', '2')->pluck('email')->toArray();

        // Authenticated user and consumer type
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        // Fetch jobs based on user type
        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = $this->fetchJobsForSuperadmin($jobId, $requestdata);
        } elseif ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = $this->fetchJobsForAdmin($requestdata);
        }

        return compact('allJobs', 'languages', 'all_customers', 'all_translators', 'requestdata');
    }

    public function userLoginFailed()
    {
        // Fetch throttles with user relationships
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);
        return compact('throttles');
    }
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email')->toArray();
        $all_translators = User::where('user_type', '2')->pluck('email')->toArray();

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = $this->fetchFilteredJobs($requestdata);
        }

        return compact('allJobs', 'languages', 'all_customers', 'all_translators', 'requestdata');
    }

    private function fetchFilteredJobs($requestdata)
    {
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.status', 'pending')
            ->where('jobs.due', '>=', Carbon::now());

        if (!empty($requestdata['lang'])) {
            $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
        }
        if (!empty($requestdata['status'])) {
            $allJobs->whereIn('jobs.status', $requestdata['status']);
        }
        if (!empty($requestdata['customer_email'])) {
            $this->applyEmailFilter($allJobs, 'jobs.user_id', $requestdata['customer_email']);
        }
        if (!empty($requestdata['translator_email'])) {
            $this->applyTranslatorEmailFilter($allJobs, $requestdata['translator_email']);
        }
        if (!empty($requestdata['filter_timetype'])) {
            $this->applyTimeFilter($allJobs, $requestdata);
        }
        if (!empty($requestdata['job_type'])) {
            $allJobs->whereIn('jobs.job_type', $requestdata['job_type']);
        }

        $allJobs->select('jobs.*', 'languages.language')
            ->orderBy('jobs.created_at', 'desc')
            ->paginate(15);

        return $allJobs;
    }

    private function applyEmailFilter($query, $field, $email)
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            $query->where($field, $user->id);
        }
    }

    private function applyTranslatorEmailFilter($query, $email)
    {
        $user = User::where('email', $email)->first();
        if ($user) {
            $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id')->toArray();
            $query->whereIn('jobs.id', $allJobIDs);
        }
    }

    private function applyTimeFilter($query, $requestdata)
    {
        $filter_timetype = $requestdata['filter_timetype'];

        if ($filter_timetype == 'created') {
            if (!empty($requestdata['from'])) {
                $query->where('jobs.created_at', '>=', $requestdata['from']);
            }
            if (!empty($requestdata['to'])) {
                $query->where('jobs.created_at', '<=', $requestdata['to'] . " 23:59:00");
            }
            $query->orderBy('jobs.created_at', 'desc');
        } elseif ($filter_timetype == 'due') {
            if (!empty($requestdata['from'])) {
                $query->where('jobs.due', '>=', $requestdata['from']);
            }
            if (!empty($requestdata['to'])) {
                $query->where('jobs.due', '<=', $requestdata['to'] . " 23:59:00");
            }
            $query->orderBy('jobs.due', 'desc');
        }
    }

    public function ignoreExpiring($id)
    {
        return $this->updateJobStatus($id, ['ignore' => 1]);
    }

    public function ignoreExpired($id)
    {
        return $this->updateJobStatus($id, ['ignore_expired' => 1]);
    }

    private function updateJobStatus($id, $data)
    {
        $job = Job::find($id);
        $job->update($data);
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid)->toArray();

        $data = [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $jobid,
            'cancel_at' => Carbon::now()
        ];

        $datareopen = [
            'status' => 'pending',
            'created_at' => Carbon::now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], Carbon::now())
        ];

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job = array_merge($job, [
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid,
            ]);

            $new_job = Job::create($job);
            $new_jobid = $new_job->id;
        }

        Translator::where('job_id', $jobid)->whereNull('cancel_at')->update(['cancel_at' => $data['cancel_at']]);
        Translator::create($data);

        if ($affectedRows) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } elseif ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;

        return sprintf($format, $hours, $minutes);
    }
}
