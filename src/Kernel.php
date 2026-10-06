<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * In production everything the application writes while it runs goes to a
     * temporary directory, the writable place every container has. The
     * application tree itself is only ever read, which is what lets the
     * production image ship it without a volume. Other environments keep the
     * cache inside the project.
     */
    public function getCacheDir(): string
    {
        if ('prod' === $this->environment) {
            return '/tmp/cache/'.$this->environment;
        }

        return parent::getCacheDir();
    }

    /**
     * The compiled container is written to a directory of its own, outside the
     * cache directory. The production image builds it and ships it, so the
     * container starts without compiling anything.
     */
    public function getBuildDir(): string
    {
        return $this->getProjectDir().'/var/build/'.$this->environment;
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
