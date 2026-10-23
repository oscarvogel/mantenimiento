<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use DateTimeImmutable;
use Throwable;

/**
 * Mensaje del informe de lecturas atrasadas para la gerencia de la empresa.
 *
 * El destinatario no es el chofer: es la duena o responsable de la empresa, que
 * no tiene por que entrar al sistema a mirar la pantalla. Por eso el mensaje
 * explica el impacto (sin lectura no hay mantenimiento calculable) y termina
 * con un enlace al control de lecturas, donde puede reclamar el envio de la
 * lectura con un toque.
 */
final class ReadingComplianceReportMessageBuilder
{
    /**
     * @param list<array{
     *     code:string,
     *     plate:string,
     *     driver:string,
     *     lastReadingAt:?string,
     *     lastKm:?int,
     *     daysSince:?int
     * }> $items
     */
    public function build(
        string $locale,
        string $companyName,
        array $items,
        int $thresholdDays,
        string $controlUrl,
        bool $pilotEnabled,
        bool $realPhoneValid,
        string $recipientName = '',
        int $maxShown = 10,
    ): string {
        $locale = strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
        $companyName = trim($companyName) !== '' ? trim($companyName) : 'Empresa';
        $thresholdDays = max(1, $thresholdDays);
        $total = count($items);
        $shown = array_slice($items, 0, max(1, $maxShown));
        $remaining = $total - count($shown);

        $pilotHeader = $pilotEnabled
            ? $this->pilotHeader($locale, $recipientName, $realPhoneValid)
            : '';

        if ($locale === 'PT') {
            $body = '*' . $companyName . "* · Manutenção\n\n"
                . "Olá 👋\n\n"
                . '🚛 *' . $this->countLabel($total, 'equipamento sem leitura', 'equipamentos sem leitura')
                . ' há mais de ' . $thresholdDays . " dias*\n\n"
                . "Estes equipamentos não estão enviando a quilometragem, por isso não dá para calcular nem controlar a manutenção:\n\n"
                . $this->items($locale, $shown)
                . ($remaining > 0 ? "\n_… e mais " . $remaining . ' equipamento(s)._' . "\n" : '')
                . "\n👉 *VER OS EQUIPAMENTOS SEM LEITURA:*\n" . $controlUrl . "\n\n"
                . "Assim que o equipamento enviar a leitura, este aviso deixa de aparecer.\n\n"
                . '_Manutenção desenvolvida por Vogel Consultoría_';

            return $pilotHeader . $body;
        }

        $body = '*' . $companyName . "* · Mantenimiento\n\n"
            . "Hola 👋\n\n"
            . '🚛 *' . $this->countLabel($total, 'equipo sin lectura', 'equipos sin lectura')
            . ' hace más de ' . $thresholdDays . " días*\n\n"
            . "Estos equipos no están reportando kilómetros, así que no se puede calcular ni controlar su mantenimiento:\n\n"
            . $this->items($locale, $shown)
            . ($remaining > 0 ? "\n_… y " . $remaining . " equipo(s) más._\n" : '')
            . "\n👉 *VER LOS EQUIPOS SIN LECTURA:*\n" . $controlUrl . "\n\n"
            . "Cuando el equipo suba su lectura, este aviso deja de aparecer.\n\n"
            . '_Mantenimiento desarrollado por Vogel Consultoría_';

        return $pilotHeader . $body;
    }

    private function countLabel(int $total, string $singular, string $plural): string
    {
        return $total . ' ' . ($total === 1 ? $singular : $plural);
    }

    /**
     * @param list<array{code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int,daysSince:?int}> $items
     */
    private function items(string $locale, array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $code = trim((string) ($item['code'] ?? ''));
            $plate = trim((string) ($item['plate'] ?? ''));
            $label = $plate !== '' && $plate !== $code ? $code . ' · ' . $plate : $code;

            $days = $item['daysSince'] ?? null;
            if ($days === null) {
                $age = $locale === 'PT' ? 'nunca registrou leitura' : 'nunca registró lectura';
            } else {
                $age = $locale === 'PT'
                    ? 'há ' . $days . ' ' . ($days === 1 ? 'dia' : 'dias')
                    : 'hace ' . $days . ' ' . ($days === 1 ? 'día' : 'días');
            }

            $lines[] = '• *' . $label . '* — ' . $age;

            $reading = $this->readingText($locale, $item);
            if ($reading !== '') {
                $lines[] = '   ' . $reading;
            }

            $driver = trim((string) ($item['driver'] ?? ''));
            if ($driver !== '') {
                $lines[] = '   ' . ($locale === 'PT' ? 'Motorista: ' : 'Chofer: ') . $driver;
            }
        }

        return implode("\n", $lines);
    }

    /** @param array{lastReadingAt:?string,lastKm:?int} $item */
    private function readingText(string $locale, array $item): string
    {
        $lastReadingAt = $item['lastReadingAt'] ?? null;
        if ($lastReadingAt === null) {
            return '';
        }

        $km = $item['lastKm'] ?? null;
        $kmText = $km === null
            ? ''
            : number_format((float) $km, 0, ',', '.') . ' km';

        try {
            $date = (new DateTimeImmutable((string) $lastReadingAt))->format('d/m/Y H:i');
        } catch (Throwable) {
            $date = $locale === 'PT' ? 'sem data disponível' : 'sin fecha disponible';
        }

        if ($kmText === '') {
            return ($locale === 'PT' ? 'Última leitura: ' : 'Última lectura: ') . $date;
        }

        return ($locale === 'PT' ? 'Última leitura: ' : 'Última lectura: ') . $kmText . ' · ' . $date;
    }

    private function pilotHeader(string $locale, string $recipientName, bool $realPhoneValid): string
    {
        $recipient = trim($recipientName) !== '' ? trim($recipientName) : 'Contacto de gerencia';
        if ($locale === 'PT') {
            return "🧪 *TESTE CONTROLADO · NÃO ENVIADO AO DESTINATÁRIO REAL*\n"
                . '*Destinatário previsto:* ' . $recipient . "\n"
                . '*Telefone real:* ' . ($realPhoneValid ? 'configurado' : 'inválido ou não informado') . "\n\n";
        }

        return "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
            . '*Destinatario previsto:* ' . $recipient . "\n"
            . '*Telefono real:* ' . ($realPhoneValid ? 'configurado' : 'no valido o no cargado') . "\n\n";
    }
}
