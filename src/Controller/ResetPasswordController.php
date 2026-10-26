<?php
declare(strict_types=1);

namespace App\Controller;

use App\Form\Type\ResetPasswordFormType;
use App\Form\Type\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ResetPasswordController extends AbstractController
{
    private string $fromAddress;
    private string $fromName;
    private string $redirectUrl;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        ParameterBagInterface $params,
    )
    {
        if ($params->get('feature_reset_password_enabled') !== true) {
            throw new NotFoundHttpException();
        }

        $this->fromAddress = $params->get('mailer_from_address');
        $this->fromName = $params->get('mailer_from_name');
        $this->redirectUrl = $params->get('reset_password_redirect_url');
    }

    #[Route('/reset-password/{loginId}', name: 'forgot_password_request', methods: ['GET', 'POST'])]
    public function request(string $loginId, Request $request, MailerInterface $mailer, RateLimiterFactoryInterface $resetPasswordIpLimiter): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $limiter = $resetPasswordIpLimiter->create($request->getClientIp());
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('error', $this->translator->trans('reset_password_request.too_many_requests'));
                return $this->redirectToRoute('forgot_password_request', ['loginId' => $loginId]);
            }

            $email = trim($form->get('email')->getData());
            $user = $this->userRepository->findOneBy(['email' => $email]);

            if ($user) {
                // Generate a reset token
                $token = (string) Uuid::v4();
                $user->setResetToken($token);
                $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));

                $this->entityManager->flush();

                // Send email
                $emailMessage = (new TemplatedEmail())
                    ->from(new Address($this->fromAddress, $this->fromName))
                    ->to($user->getEmail())
                    ->subject($this->translator->trans('reset_password_email.subject', [], 'messages'))
                    ->htmlTemplate('emails/reset_password.html.twig')
                    ->textTemplate('emails/reset_password.txt.twig')
                    ->context([
                        'resetUrl' => $this->generateUrl(
                            'reset_password',
                            ['token' => $token, 'loginId' => $loginId],
                            UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                    ]);

                try {
                    $mailer->send($emailMessage);
                } catch (TransportExceptionInterface $exception) {
                    // Catch and log mailer exceptions silently to avoid revealing whether an email exists.
                    // This prevents user enumeration even if the SMTP server is misconfigured or unavailable.
                    $this->logger->error('Failed to send reset password email.', [
                        'exception' => $exception,
                    ]);
                }
            }

            $this->addFlash('success', $this->translator->trans('reset_password_request.check_email_message'));
            return $this->redirectToRoute('forgot_password_request', ['loginId' => $loginId]);
        }

        return $this->render('reset_password/request.html.twig', [
            'form' => $form->createView(),
            'redirectUrl' => $this->getRedirectUrl($loginId),
        ]);
    }

    #[Route('/reset-password/{token}/{loginId}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, string $loginId, Request $request): Response
    {
        $user = $this->userRepository->findOneBy(['resetToken' => $token]);

        if (!$user || $user->getResetTokenExpiresAt() < new \DateTime()) {
            $this->addFlash('danger', $this->translator->trans('reset_password.invalid_token'));
            return $this->redirectToRoute('forgot_password_request', ['loginId' => $loginId]);
        }

        $form = $this->createForm(ResetPasswordFormType::class, null, [
            'action' => $request->getUri(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($form->get('plainPassword')->getData());
            $user->setResetToken(null);
            $user->setResetTokenExpiresAt(null);

            $this->entityManager->flush();

            $this->addFlash('success',
                $this->translator->trans('reset_password.success_with_login',
                    [
                        '%tagOpen%' => '<a href="' . $this->getRedirectUrl($loginId) . '">',
                        '%tagClose%' => '</a>'
                    ])
            );

            return $this->redirectToRoute('forgot_password_request', ['loginId' => $loginId]);
        }

        return $this->render('reset_password/reset.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function getRedirectUrl(string $loginId): string
    {
        return str_replace('{id}', $loginId ?? '', $this->redirectUrl);
    }
}