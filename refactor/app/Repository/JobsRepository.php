<?php

class JobsRepository implements JobsRepositoryInterface
{

    public function getAll()
    {
        return Jobs::all();
    }

    public function getTranslatorJobs(int $id, string $type = 'new')
    {
        $jobs = Job::getTranslatorJobs($id, $type);
        return $jobs->pluck('jobs')->all();
    }

    public function checkJobByUser(int $id, $job)
    {
        // TODO: Implement checkJobByUser() method.
    }

    public function modelQuery()
    {
        return Job::query();
    }
}