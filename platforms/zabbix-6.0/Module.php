<?php

namespace Modules\Governance;

use Core\CModule;
use APP;
use CMenu;
use CMenuItem;
use CWebUser;

class Module extends CModule {

    /**
     * Inicializa o módulo e injeta o menu de Governança na interface do Zabbix.
     */
    public function init(): void {
        // Validação de acesso: executa apenas para Super Admins autenticados
        if (CWebUser::getType() !== USER_TYPE_SUPER_ADMIN) {
            return;
        }

        // Identificação do idioma ativo do usuário
        $userLang = CWebUser::getLang();
        $isPt = (strpos(strtolower($userLang), 'pt_br') !== false || strpos(strtolower($userLang), 'pt') === 0);

        $mainMenuTitle = $isPt ? 'Governança' : 'Governance';
        $qualityTitle = $isPt ? 'Qualidade' : 'Quality';
        $configTitle = $isPt ? 'Configurar qualidade' : 'Configure quality';

        $submenu = new CMenu([
            (new CMenuItem($qualityTitle))->setAction('governance.quality.view'),
            (new CMenuItem($isPt ? 'Disponibilidade' : 'Availability'))
                ->setAction('governance.availability.view'),
            (new CMenuItem($configTitle))->setAction('governance.quality.config'),
            (new CMenuItem($isPt ? 'Configurar disponibilidade' : 'Configure availability'))
                ->setAction('governance.availability.config')
        ]);

        APP::Component()->get('menu.main')->add(
            (new CMenuItem($mainMenuTitle))
                ->setIcon('icon-dashboard')
                ->setSubMenu($submenu)
        );
    }
}
