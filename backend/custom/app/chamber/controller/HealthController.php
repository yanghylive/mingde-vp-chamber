<?php

namespace app\chamber\controller;

use think\Response;

final class HealthController
{
    public function index(): Response
    {
        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => [
                'service' => 'chamber',
                'api_version' => 'v1',
                'time' => time(),
            ],
        ], 'json', 200);
    }
}
