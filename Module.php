<?php

namespace Modules\Governance;

use Core\CModule;
use APP;
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
        // Um item direto usa o padrão documentado do Zabbix 6.0 e reduz os
        // pontos de falha durante a inicialização do menu.
        APP::Component()->get('menu.main')->add(
            (new CMenuItem($mainMenuTitle))->setAction('governance.quality.view')
        );
    }
}
