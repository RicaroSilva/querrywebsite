<?php
declare(strict_types=1);

namespace App\Core;

class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $errors = [])
    {
        parent::__construct($message ?: match ($status) {
            400 => 'Pedido inválido.',
            401 => 'Autenticação necessária.',
            403 => 'Sem permissão para esta ação.',
            404 => 'Não encontrado.',
            419 => 'Sessão expirada ou token CSRF inválido. Recarregue a página.',
            422 => 'Dados inválidos.',
            429 => 'Demasiadas tentativas. Tente mais tarde.',
            default => 'Erro no servidor.',
        });
    }
}
