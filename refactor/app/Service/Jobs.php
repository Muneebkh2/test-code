<?php

namespace DTApi\Service;

use http\Params;

class Jobs
{
    const CUSTOMER = 'customer';
    const TRANSLATOR = 'translator';
    public function __construct(
        private \JobsRepositoryInterface $jobsRepository,
        private \UserRepositoryInterface $user
    ){}

    /**
     * @throws \Exception
     */
    public function getAllJobs($request): array
    {
        $userId = $request->get('user_id');
        $authenticatedUserType = $request->__authenticatedUser->user_type;

        if($userId) {
            $response = $this->getJobsByUser($userId);
        } elseif($this->isAdminOrSuperAdmin($authenticatedUserType)) {
            $response = $this->getFilteredJobs($request);
        }

        return $response;
    }

    /**
     * @throws \Exception
     */
    public function getJobByUserId($userId)
    {
        $user = $this->user->find($userId);

        if ($user->user_type == self::CUSTOMER) {
            return $this->getCustomerJobs($user);
        } elseif($user->user_type == self::TRANSLATOR) {
            return $this->getTranslatorJobs($user);
        }

        throw new \Exception("error! user type didn't match with the system.");
    }


    /*
    Private Methods
    */
    private function getFilteredJobs($requestData, $limit = 'all')
    {
        $user = $requestData->__authenticatedUser;
        $consumerType = $user->consumer_type;
        $userType = $user->user_type;

        $allJobs = $this->queryJobsByConsumer($requestData, $consumerType);
        $allJobs = $this->applyFilters($requestData, $allJobs, $userType);
        $allJobs = $this->applySorting($allJobs);
        $allJobs = $this->loadRelations($allJobs);

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }
        return $allJobs;
    }

    private function queryJobsByConsumer($requestData, $consumerType)
    {
        $allJobs = $this->jobsRepository->modelQuery();

        if (isset($requestData['id']) && $requestData['id'] != '') {
            $allJobs->where('id', $requestData['id']);
            $requestData = array_only($requestData, ['id']);
        }

        if ($consumerType == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }

        return $allJobs;
    }

    private function applyFilters($requestData, $allJobs, $userType)
    {
        if ($userType == config('users.superAdmin')) {
            if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
                if (isset($requestData['count']) && $requestData['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestData['id']) && $requestData['id'] != '') {
                if (is_array($requestData['id']))
                    $allJobs->whereIn('id', $requestData['id']);
                else
                    $allJobs->where('id', $requestData['id']);
                $requestData = array_only($requestData, ['id']);
            }

            if (isset($requestData['lang']) && $requestData['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestData['lang']);
            }
            if (isset($requestData['status']) && $requestData['status'] != '') {
                $allJobs->whereIn('status', $requestData['status']);
            }
            if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestData['expired_at']);
            }
            if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
            }
            if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }
            if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "created") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }
            if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == "due") {
                if (isset($requestData['from']) && $requestData['from'] != "") {
                    $allJobs->where('due', '>=', $requestData["from"]);
                }
                if (isset($requestData['to']) && $requestData['to'] != "") {
                    $to = $requestData["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestData['job_type']);
                /*$allJobs->where('jobs.job_type', '=', $requestData['job_type']);*/
            }

            if (isset($requestData['physical'])) {
                $allJobs->where('customer_physical_type', $requestData['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestData['phone'])) {
                $allJobs->where('customer_phone_type', $requestData['phone']);
                if(isset($requestData['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestData['flagged'])) {
                $allJobs->where('flagged', $requestData['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestData['salary']) &&  $requestData['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestData) {
                    $q->where('consumer_type', $requestData['consumer_type']);
                });
            }

            if (isset($requestData['booking_type'])) {
                if ($requestData['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');
                if ($requestData['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }

        } else {
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });
                if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }
            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }
                $allJobs->orderBy('created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }
                $allJobs->orderBy('due', 'desc');
            }
        }

        return $allJobs;
    }

    private function applySorting($jobs)
    {
        return $jobs->orderBy('created_at', 'desc');
    }
    private function loadRelations($jobs)
    {
        return $jobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    }

    private function getJobsByUser(int $userId): array
    {
        $userType = '';
        $normalJobs = [];
        $emergencyJobs = [];
        $user = $this->user->find($userId);

        if ($user) {
            if ($user->is('customer')) {
                $userType = self::CUSTOMER;
                $jobs = $this->getCustomerJobs($user);
            } elseif ($user->is('translator')) {
                $userType = self::TRANSLATOR;
                $jobs = $this->getTranslatorJobs($user);
            }

            if (isset($jobs)) {
                foreach ($jobs as $jobItem) {
                    if ($jobItem->immediate == 'yes') {
                        $emergencyJobs[] = $jobItem;
                    } else {
                        $normalJobs[] = $jobItem;
                    }
                }
                $normalJobs = $this->recheckNormalJobs($normalJobs, $userId);
            }

            return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $normalJobs, 'cuser' => $user, 'usertype' => $userType];
        }

        throw new \Exception("User not fount ! ID: $userId");
    }
    private function isAdminOrSuperAdmin($userType): bool
    {
        return $userType == config('users.admin') || $userType == config('users.superAdmin');
    }
    private function getCustomerJobs($user)
    {
        return $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }
    private function getTranslatorJobs($user)
    {
        return $this->jobsRepository->getTranslatorJobs($user->id);
    }
    private function recheckNormalJobs(array $jobs, int $userId)
    {
        return collect($jobs)->each(function ($item, $key) use ($userId) {
            $item['usercheck'] = $this->jobsRepository->checkJobByUser($userId, $item);
        })->sortBy('due')->all();
    }
}