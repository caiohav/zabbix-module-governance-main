<?php

namespace Modules\Governance\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\Governance\GovernanceConfig;

class QualityConfigUpdate extends CController {

    // Mutations keep the native Zabbix SID validation enabled.
    protected function checkInput(): bool {
        $valid = $this->validateInput([
            'cards' => 'array',
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
        $selectedPage = $this->getInput('page', '');
        $url = (new CUrl('zabbix.php'))->setArgument('action', 'governance.quality.config');
        if ($selectedPage !== '') {
            $url->setArgument('page', $selectedPage);
        }
        $redirect = new CControllerResponseRedirect($url);
        // Check optional inputs explicitly: getInput(..., null) is not a safe fallback in Zabbix 6.
        $json = $this->hasInput('quality_json') ? $this->getInput('quality_json') : null;
        $revision = $this->hasInput('quality_revision') ? $this->getInput('quality_revision') : null;

        try {
            // Read the latest full configuration immediately before merging so
            // availability and any unrelated module settings remain untouched.
            $modules = API::Module()->get([
                'output' => ['moduleid', 'config'],
                'filter' => ['id' => 'zabbix_module_governance']
            ]);
            if (!$modules) {
                throw new \RuntimeException('Module not found / Módulo não encontrado.');
            }
            $merged = $modules[0]['config'];

            if ($json !== null) {
                if (strlen($json) > 3000000 || substr(ltrim($json), 0, 1) !== '[') {
                    throw new \InvalidArgumentException('Invalid or oversized page configuration / Configuração de páginas inválida ou muito grande.');
                }
                $pages = GovernanceConfig::validateQualityPages(json_decode($json, true));
                if (!is_string($revision) || !hash_equals(GovernanceConfig::qualityRevision($merged), $revision)) {
                    throw new \RuntimeException('Quality pages changed in another session. Reload the saved pages before saving / As páginas de qualidade foram alteradas em outra sessão. Recarregue as páginas salvas antes de salvar.');
                }
            }
            else {
                // A pre-upgrade form may migrate the original single page only.
                // It must never discard pages created from a newer editor.
                if ($revision !== null || array_key_exists('quality_pages', $merged)) {
                    throw new \RuntimeException('This form is outdated. Reload the quality editor / Este formulário está desatualizado. Recarregue o editor de qualidade.');
                }
                $legacyPages = [[
                    'id' => 'main', 'name' => '', 'cards' => array_values($this->getInput('cards', []))
                ]];
                $json = json_encode($legacyPages);
                $revision = GovernanceConfig::qualityRevision($merged);
                $pages = GovernanceConfig::validateQualityPages($legacyPages);
            }

            $merged['quality_pages'] = $pages;
            // The validated pages now own these cards. Keeping their legacy copy
            // would consume the shared native storage quota a second time.
            unset($merged['cards']);
            GovernanceConfig::assertModuleConfigSize($merged);
            if (!API::Module()->update([[
                'moduleid' => $modules[0]['moduleid'], 'config' => $merged
            ]])) {
                throw new \RuntimeException('Could not save quality pages / Não foi possível salvar as páginas de qualidade.');
            }
            CMessageHelper::setSuccessTitle($isPt ? 'Páginas e cards de qualidade salvos.' : 'Quality pages and cards saved.');
        }
        catch (\Exception $e) {
            CMessageHelper::setErrorTitle($isPt ? 'As páginas de qualidade não foram salvas.' : 'Quality pages were not saved.');
            CMessageHelper::addError($e->getMessage());
            $draft = ['page' => $selectedPage];
            if ($json !== null) {
                $draft['quality_json'] = $json;
                $draft['quality_revision'] = $revision ?? '';
            }
            if ($revision !== null) {
                $draft['quality_revision'] = $revision;
            }
            $redirect->setFormData($draft);
        }

        $this->setResponse($redirect);
    }
}
