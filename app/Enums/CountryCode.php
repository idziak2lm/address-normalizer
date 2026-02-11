<?php

namespace App\Enums;

enum CountryCode: string
{
    case PL = 'PL';
    case CZ = 'CZ';
    case SK = 'SK';
    case DE = 'DE';
    case AT = 'AT';
    case CH = 'CH';
    case FR = 'FR';
    case IT = 'IT';
    case ES = 'ES';
    case PT = 'PT';
    case NL = 'NL';
    case BE = 'BE';
    case LU = 'LU';
    case GB = 'GB';
    case IE = 'IE';
    case DK = 'DK';
    case SE = 'SE';
    case NO = 'NO';
    case FI = 'FI';
    case HU = 'HU';
    case RO = 'RO';
    case BG = 'BG';
    case HR = 'HR';
    case SI = 'SI';
    case GR = 'GR';
    case CY = 'CY';
    case MT = 'MT';
    case LT = 'LT';
    case LV = 'LV';
    case EE = 'EE';
    case UA = 'UA';

    public function postalCodePattern(): ?string
    {
        return match ($this) {
            self::PL => '/^\d{2}-\d{3}$/',
            self::CZ, self::SK, self::SE, self::GR => '/^\d{3}\s?\d{2}$/',
            self::DE, self::FR, self::IT, self::ES, self::FI, self::HR, self::EE => '/^\d{5}$/',
            self::GB => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',
            self::NL => '/^\d{4}\s?[A-Z]{2}$/i',
            self::AT, self::BE, self::CH, self::DK, self::NO, self::HU, self::LU, self::SI, self::BG, self::CY => '/^\d{4}$/',
            self::PT => '/^\d{4}-\d{3}$/',
            self::IE => '/^[A-Z\d]{3}\s?[A-Z\d]{4}$/i',
            self::RO => '/^\d{6}$/',
            self::LT => '/^LT-?\d{5}$/i',
            self::LV => '/^LV-?\d{4}$/i',
            self::MT => '/^[A-Z]{3}\s?\d{4}$/i',
            self::UA => '/^\d{5}$/',
        };
    }

    public static function isValid(string $code): bool
    {
        return self::tryFrom(strtoupper($code)) !== null;
    }
}
