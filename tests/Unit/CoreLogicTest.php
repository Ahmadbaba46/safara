<?php

namespace Tests\Unit;

use App\Integrations\Flights\DuffelFlightSearch;
use App\Integrations\Passport\DeepSeekPassportReader;
use App\Support\FareDecision;
use App\Support\Money;
use App\Support\Mrz;
use App\Support\PdfWriter;
use App\Support\Pricing;
use App\Support\TripRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CoreLogicTest extends TestCase
{
    public function test_icao_specimen_mrz_parses_and_validates(): void
    {
        $r = Mrz::parse('P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<', 'L898902C36UTO7408122F1204159ZE184226B<<<<<10', new \DateTimeImmutable('2026-09-27'));

        $this->assertTrue($r['valid']);
        $this->assertSame('ERIKSSON', $r['fields']['surname']);
        $this->assertSame('ANNA MARIA', $r['fields']['given_names']);
        $this->assertSame('L898902C3', $r['fields']['number']);
        $this->assertSame('1974-08-12', $r['fields']['date_of_birth']);
        $this->assertSame('2012-04-15', $r['fields']['expiry']);
    }

    public function test_a_wrong_digit_fails_the_check(): void
    {
        $r = Mrz::parse('P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<', 'L898902C36UTO7408132F1204159ZE184226B<<<<<10');

        $this->assertFalse($r['valid']);
        $this->assertFalse($r['checks']['dob']);
        $this->assertTrue($r['checks']['number']);
    }

    public function test_mrz_overrides_the_printed_reading_when_its_check_digit_passes(): void
    {
        $reading = DeepSeekPassportReader::fromModel([
            'is_passport' => true,
            'mrz_line1' => 'P<NGABELLO<<AISHA<<<<<<<<<<<<<<<<<<<<<<<<<<<',
            'mrz_line2' => 'A091234210NGA9106042F3103142<<<<<<<<<<<<<<04',
            'surname' => 'BELLO', 'given_names' => 'AISHA', 'passport_number' => 'A09I23421',
            'nationality' => 'NGA', 'date_of_birth' => null, 'sex' => 'F', 'expiry' => '2031-03-14',
            'confidence' => ['surname' => 95, 'given_names' => 95, 'passport_number' => 40, 'nationality' => 90, 'date_of_birth' => 0, 'sex' => 90, 'expiry' => 80],
        ]);

        $this->assertTrue($reading->mrzValid);
        $this->assertSame('A09123421', $reading->fields['number']);
        $this->assertSame('1991-06-04', $reading->fields['date_of_birth']);
        $this->assertTrue($reading->usable());
    }

    public function test_pricing_matches_the_design_example(): void
    {
        $this->assertSame(685000, (new Pricing(6, 10000, 1000))->quote(646200));
        $this->assertSame(110000, (new Pricing(6, 10000, 1000))->quote(100000)); // minimum margin wins
        $this->assertSame(106000, (new Pricing(6, 0, 0))->quote(100000));
    }

    #[DataProvider('decisions')]
    public function test_fare_decisions(int $paid, int $live, bool $auto, bool $absorb, bool $paused, string $expected): void
    {
        $this->assertSame($expected, FareDecision::decide($paid, $live, $auto, $absorb, 5000, $paused)->action);
    }

    public static function decisions(): array
    {
        return [
            'fits' => [685000, 671800, true, true, false, 'issue'],
            'small rise absorbed' => [685000, 689000, true, true, false, 'issue'],
            'big rise reviewed' => [1310000, 1348000, true, true, false, 'review'],
            'absorb off' => [685000, 686000, true, false, false, 'review'],
            'auto-issue off' => [685000, 600000, false, true, false, 'hold'],
            'overnight' => [685000, 600000, true, true, true, 'hold'],
        ];
    }

    public function test_overnight_window_crosses_midnight(): void
    {
        $this->assertTrue(FareDecision::inWindow('23:30', '22:00', '06:00'));
        $this->assertTrue(FareDecision::inWindow('05:59', '22:00', '06:00'));
        $this->assertFalse(FareDecision::inWindow('06:00', '22:00', '06:00'));
    }

    public function test_trip_rules_understand_english_and_hausa(): void
    {
        $rules = new TripRules(new \DateTimeImmutable('2026-09-27'));

        $a = $rules->parse('Salam. I need a flight Kano to Jeddah, 12 October, just me.');
        $this->assertSame(['KAN', 'JED', '2026-10-12', 1], [$a->origin, $a->destination, $a->departOn, $a->travellers]);

        $b = $rules->parse('daga Abuja zuwa Legas gobe mutum biyu');
        $this->assertSame(['ABV', 'LOS', '2026-09-28', 2, 'ha'], [$b->origin, $b->destination, $b->departOn, $b->travellers, $b->language]);

        $c = $rules->parse('KAN-DXB 18/10 return 25/10 2 adults');
        $this->assertSame(['KAN', 'DXB', '2026-10-18', '2026-10-25', 2], [$c->origin, $c->destination, $c->departOn, $c->returnOn, $c->travellers]);

        $d = $rules->parse('I want to go to Dubai');
        $this->assertSame([null, 'DXB'], [$d->origin, $d->destination]);

        $this->assertTrue($rules->parse('hello')->isEmpty());
    }

    public function test_money_formatting(): void
    {
        $this->assertSame('₦685,000', Money::format(685000));
        $this->assertSame('−₦21,600', Money::signed(-21600));
        $this->assertSame(1310000, Money::parse('₦1,310,000'));
    }

    public function test_duffel_durations(): void
    {
        $this->assertSame(700, DuffelFlightSearch::minutes('PT11H40M'));
        $this->assertSame(1560, DuffelFlightSearch::minutes('P1DT2H'));
        $this->assertNull(DuffelFlightSearch::minutes(null));
    }

    public function test_pdf_writer_produces_a_pdf(): void
    {
        $pdf = (new PdfWriter('Test'))->rect(0, 0, 100, 50, '#13241F')->text(40, 40, 'Total ₦685,000 (paid)', 12)->output();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('NGN 685,000 \\(paid\\)', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }
}
