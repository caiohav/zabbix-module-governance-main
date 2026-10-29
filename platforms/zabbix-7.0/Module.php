<?php

namespace Modules\Governance;

use Zabbix\Core\CModule;
use APP;
use CMenu;
use CMenuItem;
use CWebUser;

class Module extends CModule {

    /**
     * Inicializa o módulo e injeta o menu de Governança na interface do Zabbix.
     */
    public function init(): void {
        if (!GovernanceAccess::canView()) {
            return;
        }

        // Identificação do idioma ativo do usuário
        $userLang = CWebUser::getLang();
        $isPt = (strpos(strtolower($userLang), 'pt_br') !== false || strpos(strtolower($userLang), 'pt') === 0);

        $mainMenuTitle = $isPt ? 'Governança' : 'Governance';
        $qualityTitle = $isPt ? 'Qualidade' : 'Quality';
        $configTitle = $isPt ? 'Configurar qualidade' : 'Configure quality';

        $items = [];
        if (GovernanceAccess::canConfigure()) {
            $items[] = (new CMenuItem($qualityTitle))->setAction('governance.quality.view');
        }
        $items[] = (new CMenuItem($isPt ? 'Disponibilidade' : 'Availability'))
            ->setAction('governance.availability.view');
        if (GovernanceAccess::canConfigure()) {
            $items[] = (new CMenuItem($configTitle))->setAction('governance.quality.config');
            $items[] = (new CMenuItem($isPt ? 'Configurar disponibilidade' : 'Configure availability'))
                ->setAction('governance.availability.config');
        }
        $submenu = new CMenu($items);

        APP::Component()->get('menu.main')->add(
            (new CMenuItem($mainMenuTitle))
                ->setIcon(defined('ZBX_ICON_DASHBOARDS') ? ZBX_ICON_DASHBOARDS : 'zi-dashboards')
                ->setSubMenu($submenu)
        );
    }
}
