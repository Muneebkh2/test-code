<?php

interface JobsRepositoryInterface
{
    public function getAll();
    public function getTranslatorJobs( int $id, string $type = 'new');
    public function checkJobByUser(int $id, $job);
    public function modelQuery();
}