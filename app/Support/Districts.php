<?php

namespace App\Support;

final class Districts
{
    private const LIST = [
        'Bagerhat', 'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura', 'Brahmanbaria', 'Chandpur',
        'Chapainawabganj', 'Chattogram', 'Chuadanga', 'Cumilla', "Cox's Bazar", 'Dhaka', 'Dinajpur',
        'Faridpur', 'Feni', 'Gaibandha', 'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore',
        'Jhalokathi', 'Jhenaidah', 'Joypurhat', 'Khagrachhari', 'Khulna', 'Kishoreganj', 'Kurigram',
        'Kushtia', 'Lakshmipur', 'Lalmonirhat', 'Madaripur', 'Magura', 'Manikganj', 'Meherpur',
        'Moulvibazar', 'Munshiganj', 'Mymensingh', 'Naogaon', 'Narail', 'Narayanganj', 'Narsingdi',
        'Natore', 'Netrokona', 'Nilphamari', 'Noakhali', 'Pabna', 'Panchagarh', 'Patuakhali', 'Pirojpur',
        'Rajbari', 'Rajshahi', 'Rangamati', 'Rangpur', 'Satkhira', 'Shariatpur', 'Sherpur', 'Sirajganj',
        'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon',
    ];

    /** Other spellings people use. The keys are written the same way as key() writes them. */
    private const ALIASES = [
        'chittagong' => 'Chattogram',
        'comilla' => 'Cumilla',
        'barisal' => 'Barishal',
        'jessore' => 'Jashore',
        'bogra' => 'Bogura',
        'coxbazar' => "Cox's Bazar",
        'jhalakathi' => 'Jhalokathi',
        'khagrachari' => 'Khagrachhari',
        'maulvibazar' => 'Moulvibazar',
        'netrakona' => 'Netrokona',
        'narshingdi' => 'Narsingdi',
        'laxmipur' => 'Lakshmipur',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return self::LIST;
    }

    /** The official spelling of a district, or null when it is not one. */
    public static function find(string $input): ?string
    {
        $key = self::key($input);

        foreach (self::LIST as $district) {
            if (self::key($district) === $key) {
                return $district;
            }
        }

        return self::ALIASES[$key] ?? null;
    }

    private static function key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', $value));
    }
}
