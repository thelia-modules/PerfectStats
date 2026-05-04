<?php

namespace PerfectStats\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

class AdminMenuHook extends BaseHook
{

    public function onMainInTopMenuItems(HookRenderEvent $event): void
    {
        $event->add(
            $this->render('PerfectStats/hook/main.in.top.menu.items.html', [])
        );
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "main.in-top-menu-items" => [
                [
                    "type" => "back",
                    "method" => "onMainInTopMenuItems"
                ],
            ]
        ];
    }
}
