<?php

namespace LingoWP\Backend;

interface TranslationBackendPort
{
    public function submitTasks(array $payload): array;

    public function pullResults(int $cursor = 0): array;

    public function ackResults(array $items): array;
}
