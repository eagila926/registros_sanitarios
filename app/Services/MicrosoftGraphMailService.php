<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MicrosoftGraphMailService
{
    private function configuracion(string $clave): string
    {
        $valor = trim((string) config(
            'microsoft_graph.' . $clave,
            ''
        ));

        if ($valor === '') {
            throw new RuntimeException(
                "Falta configuración Microsoft Graph: {$clave}"
            );
        }

        return $valor;
    }

    public function obtenerToken(): string
    {
        $tenant = $this->configuracion('tenant_id');

        $response = Http::asForm()
            ->timeout(20)
            ->post(
                "https://login.microsoftonline.com/"
                . rawurlencode($tenant)
                . "/oauth2/v2.0/token",
                [
                    'client_id' => $this->configuracion('client_id'),
                    'client_secret' => $this->configuracion('client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );

        if (!$response->successful()) {
            Log::warning('Error autenticación Microsoft Graph', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                'No se pudo obtener el token de Microsoft Graph. '
                . 'HTTP ' . $response->status()
            );
        }

        $token = $response->json('access_token');

        if (!is_string($token) || $token === '') {
            throw new RuntimeException(
                'Microsoft Graph no devolvió un token válido.'
            );
        }

        return $token;
    }

    public function enviar(
        string $destinatario,
        string $asunto,
        string $contenido,
        array $copias = []
    ): void {
        if (!config('microsoft_graph.enabled', false)) {
            throw new RuntimeException(
                'El envío Microsoft Graph está desactivado.'
            );
        }

        $mailbox = $this->configuracion('mailbox');

        $correos = array_merge([$destinatario], $copias);

        foreach ($correos as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Se encontró una dirección de correo inválida.'
                );
            }
        }

        $recipiente = static fn (string $email): array => [
            'emailAddress' => [
                'address' => $email,
            ],
        ];

        $response = Http::withToken($this->obtenerToken())
            ->acceptJson()
            ->timeout(30)
            ->post(
                'https://graph.microsoft.com/v1.0/users/'
                . rawurlencode($mailbox)
                . '/sendMail',
                [
                    'message' => [
                        'subject' => $asunto,
                        'body' => [
                            'contentType' => 'Text',
                            'content' => $contenido,
                        ],
                        'toRecipients' => [
                            $recipiente($destinatario),
                        ],
                        'ccRecipients' => array_map(
                            $recipiente,
                            $copias
                        ),
                    ],
                    'saveToSentItems' => true,
                ]
            );

        if ($response->status() !== 202) {
            Log::warning('Microsoft Graph rechazó el envío', [
                'status' => $response->status(),
                'request_id' => $response->header('request-id'),
            ]);

            throw new RuntimeException(
                'Microsoft Graph no aceptó el correo. '
                . 'HTTP ' . $response->status()
            );
        }
    }
}
