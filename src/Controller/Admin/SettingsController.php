<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Controller\Admin;

use Agent001\SyliusAiAssistantPlugin\Channel\ChannelConfigurator;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Agent001\SyliusAiAssistantPlugin\Settings\SettingsViewProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class SettingsController extends AbstractController
{
    private const CONNECT_RESULTS = [
        'success' => ['success', 'agent001_sylius_ai_assistant.connect.success'],
        'exchange_failed' => ['error', 'agent001_sylius_ai_assistant.connect.exchange_failed'],
    ];

    public function __construct(
        private readonly SettingsViewProvider $viewProvider,
        private readonly ChannelConfigurator $configurator,
    ) {
    }

    public function index(Request $request): Response
    {
        $result = self::CONNECT_RESULTS[(string) $request->query->get('connect')] ?? null;
        if (null !== $result) {
            $this->addFlash($result[0], $result[1]);

            return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
        }

        return $this->render('@Agent001SyliusAiAssistantPlugin/admin/settings.html.twig', $this->viewProvider->view());
    }

    public function agents(Request $request): Response
    {
        return $this->handle($request, 'agent001_sylius_ai_assistant_agents', function () use ($request): void {
            $this->configurator->assignAgents($request->request->all('agent'), $request);
            $this->addFlash('success', 'agent001_sylius_ai_assistant.settings.saved');
        });
    }

    public function toggle(Request $request): Response
    {
        return $this->handle($request, 'agent001_sylius_ai_assistant_toggle', function () use ($request): void {
            $active = $this->configurator->toggle((string) $request->request->get('channel'));
            $this->addFlash('success', $active
                ? 'agent001_sylius_ai_assistant.settings.agent_enabled'
                : 'agent001_sylius_ai_assistant.settings.agent_disabled');
        });
    }

    public function catalog(Request $request): Response
    {
        return $this->handle($request, 'agent001_sylius_ai_assistant_catalog', function () use ($request): void {
            $this->configurator->synchronize(
                (string) $request->request->get('channel'),
                $request->request->all('taxons'),
            );
            $this->addFlash('success', 'agent001_sylius_ai_assistant.settings.sync_queued');
        });
    }

    public function progress(): JsonResponse
    {
        return new JsonResponse($this->viewProvider->progress(), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function handle(Request $request, string $csrfId, callable $operation): Response
    {
        if (!$this->isCsrfTokenValid($csrfId, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'agent001_sylius_ai_assistant.invalid_csrf');

            return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
        }

        try {
            $operation();
        } catch (Agent001Exception $e) {
            $this->addFlash('error', ['message' => $e->getMessage(), 'parameters' => $e->parameters]);
        }

        return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
    }
}
