<?php

declare(strict_types=1);

namespace ExternalModules {
    class AbstractExternalModule
    {
        public object $framework;

        public function redcap_module_link_check_display($project_id, $link)
        {
            return null;
        }
    }
}

namespace {
    use DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule;

    require dirname(__DIR__) . '/PDFSealerExternalModule.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    final class FakeFramework
    {
        public mixed $hideLink = null;

        public function getProjectSetting(string $key, int $projectId): mixed
        {
            check($key === 'hide-project-trust-link' && $projectId === 461, 'Wrong project setting read');
            return $this->hideLink;
        }
    }

    $framework = new FakeFramework();
    $module = new PDFSealerExternalModule();
    $module->framework = $framework;
    $link = ['key' => 'public-trust', 'url' => 'project-trust-link.php'];

    check($module->redcap_module_link_check_display(461, $link) === $link,
        'Project trust link should be visible by default');
    $framework->hideLink = true;
    check($module->redcap_module_link_check_display(461, $link) === null,
        'Project opt-out did not hide the trust link');
    $framework->hideLink = false;
    check($module->redcap_module_link_check_display(461, $link) === $link,
        'Clearing the opt-out did not restore the trust link');
    check($module->redcap_module_link_check_display(461, ['key' => 'project-status']) === null,
        'Project status bypassed the normal Design-right permission check');
    check($module->redcap_module_link_check_display(461, ['key' => 'other']) === null,
        'Unrelated project link bypassed its normal permission check');
    check($module->redcap_module_link_check_display(null, $link) === null,
        'Project trust link appeared outside a project');

    $config = json_decode(file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    check($config['project-settings'][0]['type'] === 'checkbox'
        && $config['project-settings'][0]['key'] === 'hide-project-trust-link',
        'Project opt-out is not configured as a checkbox');
    check($config['links']['project'][0]['key'] === 'public-trust'
        && $config['links']['project'][0]['url'] === 'project-trust-link.php',
        'Project menu link does not use the redirect page');

    echo "Project trust link: default visibility, opt-out, and normal permissions passed.\n";
}
