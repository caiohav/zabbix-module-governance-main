<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\Governance\GovernanceConfig;

class QualityConfig extends CController {

    protected function init(): void {
        $this->disableSIDvalidation();
    }

    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'quality_json' => 'string',
            'quality_revision' => 'string',
            'page' => 'string'
        ]);
        if (!$valid) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $valid;
    }

    protected function checkPermissions(): bool {
        return ($this->getUserType() == USER_TYPE_SUPER_ADMIN);
    }

    protected function doAction(): void {
        $isPt = (strpos(strtolower(CWebUser::getLang()), 'pt') === 0);
        $modules = API::Module()->get([
            'output' => ['moduleid', 'config'],
            'filter' => ['id' => 'zabbix_module_governance']
        ]);

        $config = $modules ? $modules[0]['config'] : [];
        $pages = GovernanceConfig::getQualityPages($config);
        $revision = GovernanceConfig::qualityRevision($config);

        // Return the draft and the revision the user actually reviewed. Granting
        // the current revision to a stale draft would bypass the conflict check.
        if ($this->hasInput('quality_json')) {
            $json = $this->getInput('quality_json');
            if (strlen($json) <= 3000000 && substr(ltrim($json), 0, 1) === '[') {
                $draft = json_decode($json, true);
                if (is_array($draft) && array_values($draft) === $draft) {
                    $pages = $draft;
                }
            }
        }

        $requestedPage = $this->getInput('page', '');
        $selectedPage = isset($pages[0]) && is_array($pages[0]) && is_string($pages[0]['id'] ?? null)
            ? $pages[0]['id'] : '';
        foreach ($pages as $page) {
            if (is_array($page) && ($page['id'] ?? null) === $requestedPage) {
                $selectedPage = $requestedPage;
                break;
            }
        }

        $reviewedRevision = $this->getInput('quality_revision', $this->hasInput('quality_json') ? '' : $revision);
        $this->setResponse(new CControllerResponseData([
            'page_title' => $isPt ? 'Páginas e cards de qualidade' : 'Quality pages and cards',
            'pages' => $pages,
            'revision' => $reviewedRevision,
            'selected_page' => $selectedPage,
            'conflict' => !hash_equals($revision, $reviewedRevision),
            'draft_json' => $this->getInput('quality_json', null),
            'is_pt' => $isPt,
            'is_dark' => self::isDarkTheme()
        ]));
    }

    private static function isDarkTheme(): bool {
        return (strpos(strtolower(getUserTheme(CWebUser::$data)), 'dark') !== false);
    }
}
