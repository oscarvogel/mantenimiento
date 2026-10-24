<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

/** Boundary for the ATIC API. Provider payloads stop at this port. */
interface AutotracApi
{
    /** @param array{subscriptionKey:string,username:string,password:string,endpoint?:string} $credentials
     *  @return list<array{Code:int,Name:string}>
     */
    public function accounts(array $credentials): array;

    /** @param array{subscriptionKey:string,username:string,password:string,accountCode:int,endpoint?:string} $credentials
     *  @return list<array<string,mixed>>
     */
    public function vehicles(array $credentials): array;

    /** @param array{subscriptionKey:string,username:string,password:string,accountCode:int,endpoint?:string} $credentials
     *  @return list<array<string,mixed>>
     */
    public function positions(array $credentials, int $vehicleCode): array;

    /** @param array{subscriptionKey:string,username:string,password:string,accountCode:int,endpoint?:string} $credentials
     *  @return list<array<string,mixed>>
     */
    public function expandedAlerts(array $credentials, int $vehicleCode): array;
}
