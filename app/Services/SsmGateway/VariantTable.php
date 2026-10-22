<?php

namespace App\Services\SsmGateway;

/**
 * Mirror of infominaAI-BE src/ssm-gateway/constants/ssm-variant-ids.stg.const.ts.
 * Keep in sync by hand; the unit test only checks internal consistency.
 */
final class VariantTable
{
    public const STAGING = [
        'ATTESTATION_OF_COMPANY_GOOD_STANDING_ACGS' => ['NON_CTC' => ['EN' => 2, 'MY' => 1]],
        'COMPANY_PROFILE' => ['CTC' => ['EN' => 160, 'MY' => 161], 'NON_CTC' => ['EN' => 158, 'MY' => 159]],
        'BUSINESS_PROFILE' => ['CTC' => ['EN' => 164, 'MY' => 165], 'NON_CTC' => ['EN' => 162, 'MY' => 163]],
        'AUDIT_FIRM_PROFILE' => ['CTC' => ['EN' => 176, 'MY' => 177], 'NON_CTC' => ['EN' => 174, 'MY' => 175]],
        'BUSINESS_EXPIRY_CONFIRMATION_LETTER' => ['CTC' => ['EN' => 180, 'MY' => 181], 'NON_CTC' => ['EN' => 178, 'MY' => 179]],
        'COMPANY_CHARGES' => ['CTC' => ['EN' => 184, 'MY' => 185], 'NON_CTC' => ['EN' => 182, 'MY' => 183]],
        'SHAREHOLDER_LISTING_AND_ANALYSIS' => ['NON_CTC' => ['EN' => 190, 'MY' => 222]],
        'BUSINESS_RE_REGISTER_CONFIRMATION_LETTER_BRECLE' => ['CTC' => ['EN' => 979, 'MY' => 980], 'NON_CTC' => ['EN' => 977, 'MY' => 978]],
        'LLP_PROFILE' => ['CTC' => ['EN' => 988, 'MY' => 989], 'NON_CTC' => ['EN' => 986, 'MY' => 987]],
        'ATTESTATION_OF_LLP_GOOD_STANDING' => ['NON_CTC' => ['EN' => 992, 'MY' => 993]],
        'CERTIFICATE_OF_INCORPORATION' => ['CTC' => ['EN' => 108, 'MY' => 109], 'NON_CTC' => ['EN' => 106, 'MY' => 107]],
        'CERTIFICATE_OF_FOREIGN_COMPANY' => ['CTC' => ['EN' => 112], 'NON_CTC' => ['EN' => 110]],
        'CERTIFICATE_CHANGE_OF_COMPANY_NAME' => ['CTC' => ['EN' => 116, 'MY' => 117], 'NON_CTC' => ['EN' => 114, 'MY' => 115]],
        'CERTIFICATE_OF_CONVERSION' => ['CTC' => ['EN' => 120, 'MY' => 121], 'NON_CTC' => ['EN' => 118, 'MY' => 119]],
        'BUSINESS_CERTIFICATE' => ['CTC' => ['EN' => 166, 'MY' => 203]],
        'LLP_CERTIFICATE_OF_REGISTRATION' => ['CTC' => ['EN' => 1019, 'MY' => 1020], 'NON_CTC' => ['EN' => 990, 'MY' => 991]],
        'LLP_CERTIFICATE_CHANGE_OF_NAME' => ['CTC' => ['EN' => 1021, 'MY' => 1022], 'NON_CTC' => ['EN' => 994, 'MY' => 995]],
        'PARTICULARS_OF_DIRECTORS_OFFICERS' => ['CTC' => ['EN' => 144, 'MY' => 145], 'NON_CTC' => ['EN' => 142, 'MY' => 143]],
        'PARTICULARS_OF_REGISTERED_ADDRESS' => ['CTC' => ['EN' => 148, 'MY' => 149], 'NON_CTC' => ['EN' => 146, 'MY' => 147]],
        'PARTICULAR_OF_SHAREHOLDERS' => ['CTC' => ['EN' => 152, 'MY' => 153], 'NON_CTC' => ['EN' => 150, 'MY' => 151]],
        'PARTICULARS_OF_SHARE_CAPITAL' => ['CTC' => ['EN' => 156, 'MY' => 157], 'NON_CTC' => ['EN' => 154, 'MY' => 155]],
        'PARTICULARS_OF_COMPANY_SECRETARY' => ['CTC' => ['EN' => 172, 'MY' => 173], 'NON_CTC' => ['EN' => 170, 'MY' => 171]],
        'PARTICULARS_OF_CHANGE_OF_COMPANY_NAME' => ['NON_CTC' => ['EN' => 186, 'MY' => 263]],
        'FINANCIAL_HISTORICAL_COMPARISON' => ['CTC' => ['EN' => 124, 'MY' => 125], 'NON_CTC' => ['EN' => 122, 'MY' => 123]],
        'FINANCIAL_COMPARISON_2_YEARS' => ['NON_CTC' => ['EN' => 126]],
        'FINANCIAL_COMPARISON_3_YEARS' => ['NON_CTC' => ['EN' => 130]],
        'FINANCIAL_COMPARISON_5_YEARS' => ['NON_CTC' => ['EN' => 134]],
        'FINANCIAL_COMPARISON_10_YEARS' => ['NON_CTC' => ['EN' => 138]],
        'PARTNER_INVOLVEMENT' => ['CTC' => ['EN' => 1025, 'MY' => 1023], 'NON_CTC' => ['EN' => 1026, 'MY' => 1024]],
        'AUDITOR_INVOLVEMENT' => ['CTC' => ['EN' => 1028, 'MY' => 1030], 'NON_CTC' => ['EN' => 1027, 'MY' => 1029]],
        'BUSINESS_OWNERSHIP' => ['CTC' => ['EN' => 1033, 'MY' => 1031], 'NON_CTC' => ['EN' => 1034, 'MY' => 1032]],
        'LIQUIDATOR_INVOLVEMENT' => ['CTC' => ['EN' => 1037, 'MY' => 1035], 'NON_CTC' => ['EN' => 1038, 'MY' => 1036]],
        'SECRETARY_INVOLVEMENT' => ['CTC' => ['EN' => 1041, 'MY' => 1039], 'NON_CTC' => ['EN' => 1042, 'MY' => 1040]],
        'SHAREHOLDER_INVOLVEMENT' => ['CTC' => ['EN' => 1045, 'MY' => 1043], 'NON_CTC' => ['EN' => 1046, 'MY' => 1044]],
        'DIRECTOR_INVOLVEMENT' => ['CTC' => ['EN' => 1048, 'MY' => 1050], 'NON_CTC' => ['EN' => 1047, 'MY' => 1049]],
    ];
}
