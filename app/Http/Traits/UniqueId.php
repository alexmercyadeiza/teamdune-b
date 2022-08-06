<?php

namespace App\Traits;

trait UniqueId {
    protected function sec_gen() {
        return 'live_sk_' . substr(md5(time()), 0, 24);
    }

    protected function uuid()
    {
        return substr(md5(time()), 0, 24);
    }

    protected function uuid_ag()
    {
        return substr(md5(time()), 0, 6);
    }

    protected function uuid_ag2()
    {
        return substr(md5(microtime()), 0, 6);
    }
}

