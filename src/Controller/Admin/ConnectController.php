<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Controller\Admin;

use Agent001\SyliusAiAssistantPlugin\Channel\StoreChannels;
use Agent001\SyliusAiAssistantPlugin\Connect\ConnectionManager;
use Agent001\SyliusAiAssistantPlugin\Exception\Agent001Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMINISTRATION_ACCESS')]
final class ConnectController extends AbstractController
{
    private const CODE_MAX_LENGTH = 2048;

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function start(Request $request): Response
    {
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('agent001_sylius_ai_assistant_connect', $token)) {
            $this->addFlash('error', 'agent001_sylius_ai_assistant.invalid_csrf');

            return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
        }

        try {
            $url = $this->connectionManager->start(
                $request->getSchemeAndHttpHost(),
                $this->generateUrl('agent001_sylius_ai_assistant_admin_connect_callback'),
                $this->generateUrl(
                    'agent001_sylius_ai_assistant_admin_settings',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                $this->initiator($request),
            );
        } catch (Agent001Exception $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
        }

        return new RedirectResponse($url);
    }

    public function callback(Request $request): Response
    {
        $state = $this->connectionManager->consumeState(
            (string) $request->query->get('state'),
            $this->initiator($request),
        );
        $origin = StoreChannels::origin($request->getSchemeAndHttpHost());
        if (null === $state || StoreChannels::origin($state['site']) !== $origin) {
            return new Response(
                $this->translator->trans('agent001_sylius_ai_assistant.connect.invalid_state'),
                Response::HTTP_BAD_REQUEST,
                ['Cache-Control' => 'no-store'],
            );
        }

        $code = (string) $request->query->get('code');
        $success = '' !== $code &&
            strlen($code) <= self::CODE_MAX_LENGTH &&
            $this->connectionManager->complete($state, $code);

        $separator = str_contains($state['settings_url'], '?') ? '&' : '?';

        $result = $success ? 'success' : 'exchange_failed';

        return new RedirectResponse($state['settings_url'] . $separator . 'connect=' . $result, Response::HTTP_FOUND, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function disconnect(Request $request): Response
    {
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('agent001_sylius_ai_assistant_disconnect', $token)) {
            $this->addFlash('error', 'agent001_sylius_ai_assistant.invalid_csrf');

            return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
        }

        try {
            $this->connectionManager->disconnect();
            $this->addFlash('success', 'agent001_sylius_ai_assistant.disconnect.success');
        } catch (Agent001Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('agent001_sylius_ai_assistant_admin_settings');
    }

    private function initiator(Request $request): string
    {
        return (string) $this->getUser()?->getUserIdentifier() . "\0" . $request->getSession()->getId();
    }
}
