<?php
namespace Modules\LocationMap;
use Zabbix\Core\CModule,
    APP,
    CMenuItem;

class Module extends CModule {
    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Discovery'),
                (new CMenuItem(_('Location Map')))->setAction('map.view')
            );
    }
}