<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final class ReadingReminderMessageBuilder
{
    /**
     * @param array{fecha_lectura:string|null,kilometraje:int|null}|null $lastReading
     */
    public function build(
        string $locale,
        string $stage,
        string $driverFullName,
        string $equipmentLabel,
        string $url,
        string $companyName,
        bool $pilotEnabled,
        bool $realPhoneValid,
        ?array $lastReading = null,
    ): string {
        $locale = strtoupper(trim($locale)) === 'PT' ? 'PT' : 'ES';
        $stage = strtolower(trim($stage));
        $driverFullName = trim($driverFullName);
        $firstName = trim(explode(' ', $driverFullName)[0] ?? '');

        $pilotHeader = $pilotEnabled
            ? $this->pilotHeader($locale, $driverFullName, $realPhoneValid)
            : '';

        if ($locale === 'PT') {
            $greeting = $firstName === '' ? 'Olá 👋' : 'Olá ' . $firstName . ' 👋';
            $opening = $stage === 'manual'
                ? 'Lembramos que você deve informar a quilometragem atual do veículo *' . $equipmentLabel . '*.'
                : (($stage === 'initial'
                    ? 'Precisamos que você informe a quilometragem atual'
                    : 'Ainda falta informar a quilometragem desta semana')
                    . ' do veículo *' . $equipmentLabel . '*.');

            $lastReadingBlock = $stage === 'manual'
                ? "\n\n*Última leitura registrada:*\n" . $this->lastReadingText($locale, $lastReading)
                : '';

            return $pilotHeader
                . '*' . ($companyName !== '' ? $companyName : 'Empresa') . "* · Manutenção\n\n"
                . $greeting . "\n\n"
                . $opening . $lastReadingBlock . "\n\n"
                . "*Importante:* 📸 *para registrar a leitura é obrigatório tirar ou enviar uma foto do hodômetro onde a quilometragem esteja visível.*\n\n"
                . "*Faça assim:*\n"
                . "1️⃣ Toque no link abaixo.\n"
                . "2️⃣ Tire ou selecione uma foto do hodômetro.\n"
                . "3️⃣ O sistema tentará ler automaticamente a quilometragem.\n"
                . "4️⃣ Confira se o valor detectado está correto e corrija se necessário.\n"
                . "5️⃣ Toque em *Registrar leitura*.\n\n"
                . "👉 *ABRIR PARA INFORMAR A QUILOMETRAGEM:*\n" . $url . "\n\n"
                . "Quando aparecer *“Leitura registrada”*, terminou e você já pode fechar a tela. ✅\n\n"
                . "*Não precisa responder esta mensagem.*";
        }

        $greeting = $firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋';
        $opening = $stage === 'manual'
            ? 'Te recordamos registrar el kilometraje actualizado del equipo *' . $equipmentLabel . '*.'
            : (($stage === 'initial'
                ? 'Necesitamos que informes los kilómetros actuales'
                : 'Todavía falta que informes los kilómetros de esta semana')
                . ' del vehículo *' . $equipmentLabel . '*.');

        $lastReadingBlock = $stage === 'manual'
            ? "\n\n*Última lectura registrada:*\n" . $this->lastReadingText($locale, $lastReading)
            : '';

        return $pilotHeader
            . '*' . ($companyName !== '' ? $companyName : 'Empresa') . "* · Mantenimiento\n\n"
            . $greeting . "\n\n"
            . $opening . $lastReadingBlock . "\n\n"
            . "*Importante:* 📸 *para registrar la lectura es obligatorio sacar o subir una foto del odómetro donde se vea el kilometraje.*\n\n"
            . "*Hacé esto:*\n"
            . "1️⃣ Tocá el enlace de abajo.\n"
            . "2️⃣ Sacá o seleccioná una foto del odómetro.\n"
            . "3️⃣ El sistema intentará leer automáticamente los kilómetros.\n"
            . "4️⃣ Revisá que el valor detectado sea correcto y corregilo si hace falta.\n"
            . "5️⃣ Tocá *Registrar lectura*.\n\n"
            . "👉 *ABRIR PARA CARGAR LOS KM:*\n" . $url . "\n\n"
            . "Cuando aparezca *“Lectura registrada”*, ya terminaste y podés cerrar la pantalla. ✅\n\n"
            . "*No hace falta responder este WhatsApp.*";
    }

    private function pilotHeader(string $locale, string $driverFullName, bool $realPhoneValid): string
    {
        if ($locale === 'PT') {
            return "🧪 *TESTE CONTROLADO · NÃO ENVIADO AO DESTINATÁRIO REAL*\n"
                . '*Destinatário previsto:* ' . ($driverFullName === '' ? 'Motorista atribuído' : $driverFullName) . "\n"
                . '*Telefone real:* ' . ($realPhoneValid ? 'configurado' : 'inválido ou não informado') . "\n\n";
        }

        return "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
            . '*Destinatario previsto:* ' . ($driverFullName === '' ? 'Chofer asignado' : $driverFullName) . "\n"
            . '*Teléfono real:* ' . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n";
    }

    /** @param array{fecha_lectura:string|null,kilometraje:int|null}|null $lastReading */
    private function lastReadingText(string $locale, ?array $lastReading): string
    {
        if ($lastReading === null || ($lastReading['fecha_lectura'] ?? null) === null) {
            return $locale === 'PT'
                ? 'Ainda não temos uma leitura registrada para este equipamento.'
                : 'Todavía no tenemos una lectura registrada para este equipo.';
        }

        $km = ($lastReading['kilometraje'] ?? null) === null
            ? null
            : number_format((float) $lastReading['kilometraje'], 0, ',', '.');

        try {
            $date = (new \DateTimeImmutable((string) $lastReading['fecha_lectura']))->format('d/m/Y H:i');
        } catch (\Throwable) {
            $date = $locale === 'PT' ? 'sem data disponível' : 'sin fecha disponible';
        }

        return ($km === null ? '' : $km . ' km') . ($km === null ? '' : ' — ') . $date;
    }
}
