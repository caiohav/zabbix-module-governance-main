<?php

namespace Modules\Governance\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Governance\GovernanceConfig;
use Modules\Governance\GovernanceAccess;
use Modules\Governance\GovernanceModuleConfig;

/** The document request loads configuration only. Metrics run after the page is usable. */
class QualityView extends CController {
    protected function init(): void {
        if (method_exists($this, 'disableCsrfValidation')) {
            $this->disableCsrfValidation();
        }
        else {
            $this->disableSIDvalidation();
        }
    }
    protected function checkPermissions(): bool { return GovernanceAccess::canConfigure(); }
    protected function checkInput(): bool {
        return $this->validateInput(['groupids' => 'array_db hstgrp.groupid', 'page' => 'string']);
    }
    protected function doAction(): void {
        $isPt = strpos(strtolower(CWebUser::getLang()), 'pt') === 0;
        $error = null; $pages = []; $revision = '';
        try {
            $config = GovernanceModuleConfig::read();
            $pages = GovernanceConfig::getQualityPages($config);
            $revision = GovernanceConfig::qualityRevision($config);
        }
        catch (\Throwable $e) {
            $error = $isPt ? 'Não foi possível carregar as regras. Recarregue a página.' : 'Cannot load the rules. Reload the page.';
        }
        $selected = $pages[0] ?? ['id' => '', 'name' => '', 'cards' => []];
        foreach ($pages as $page) { if ($page['id'] === $this->getInput('page', '')) { $selected = $page; break; } }
        $response = new CControllerResponseData([
            'is_pt' => $isPt, 'is_dark' => strpos(strtolower(getUserTheme(CWebUser::$data)), 'dark') !== false,
            'page_title' => $isPt ? 'Qualidade do monitoramento' : 'Monitoring quality',
            'pages' => $pages, 'selected_page' => $selected['id'], 'page_name' => $selected['name'],
            'can_configure' => GovernanceAccess::canConfigure(),
            'cards' => $selected['cards'], 'cards_count' => count($selected['cards']),
            'groupids' => $this->getInput('groupids', []), 'revision' => $revision, 'error' => $error
        ]);
        $response->setTitle($response->getData()['page_title']);
        $this->setResponse($response);
    }
}
