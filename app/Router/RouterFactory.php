<?php

declare(strict_types=1);

namespace App\Router;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	use Nette\StaticClass;

	public static function createRouter(): RouteList
	{
		$router = new RouteList;
        $router->addRoute('page[/<link>]', 'Page:show');

        // Události — zrušení registrace
        $router->addRoute('udalost/registrace/zrusit/<token>', 'Home:cancelRegistration');

        // Z&N — specifické trasy pro token-based akce
        $router->addRoute('zn/sighting/<token>', 'ZN:sighting');
        $router->addRoute('zn/close/<token>[/<status>]', ['presenter' => 'ZN', 'action' => 'close', 'status' => 'found']);
        $router->addRoute('zn/confirm/<token>', 'ZN:confirm');
        $router->addRoute('zn/<action>[/<id>]', 'ZN:default');

        // Přijetí pozvánky správce azylu
        $router->addRoute('azyl/prijmout-pozvani/<token>', 'Home:acceptInvite');

        // Azyl slug route — /u/<slug> avoids conflict with AzylPresenter routes at /azyl/...
        $router->addRoute('u/<slug [a-z0-9-]+>', 'Home:azyl');

		$router->addRoute('<presenter>/<action>[/<id>]', 'Home:default');

        return $router;
	}
}
