<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadInteraction;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Build-plan 04 demo data — names/details are the example schools/leads shown
 * in docs/storyboard/leads.html and lead-detail.html, same "placeholder
 * pending the real data" spirit as ProgramsCatalogSeeder (build-plan 03).
 */
class LeadsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::first();
        $landingPage = LeadSource::where('name', 'דף נחיתה')->first();
        $referral = LeadSource::where('name', 'הפניה')->first();
        $repeatInquiry = LeadSource::where('name', 'פנייה חוזרת')->first();

        $newStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );
        $inProcessStatus = StatusDefinition::where('scope', 'lead')->where('name', 'בתהליך מכירה')->first();
        $closedLostStatus = StatusDefinition::where('scope', 'lead')->where('name', 'נסגר ללא מכירה')->first();

        $premiumProgram = Program::where('is_premium', true)->first();
        $monthlyProgram = Program::where('is_premium', false)->where('is_subscription_type', false)->first();
        $subscriptionProgram = Program::where('is_subscription_type', true)->first();

        // ----- בית ספר יובלים — בתהליך מכירה, אינטראקציה + Up Follow מתועדים -----
        $yuvalim = School::create([
            'name' => 'בית ספר יובלים', 'city' => 'רעננה', 'phone' => '09-1112223',
        ]);
        Contact::create([
            'school_id' => $yuvalim->id, 'name' => 'מירב כהן', 'role' => 'רכזת',
            'phone' => '052-1112223', 'is_primary' => true,
        ]);
        Contact::create([
            'school_id' => $yuvalim->id, 'name' => 'דנה אורן', 'role' => 'מנהלת', 'phone' => '03-9876543',
        ]);
        $yuvalimLead = Lead::create([
            'school_id' => $yuvalim->id, 'assigned_user_id' => $owner?->id, 'lead_source_id' => $landingPage?->id,
            'status_id' => $inProcessStatus?->id ?? $newStatus->id,
            'email' => 'merav@yuvalim.example', 'phone' => '052-1112223',
            'notes' => 'מעוניינים להתחיל בתחילת אוקטובר, תואם לתקציב שנתי.',
        ]);
        if ($premiumProgram) {
            $yuvalimLead->interestedPrograms()->attach($premiumProgram->id);
        }
        LeadInteraction::create([
            'lead_id' => $yuvalimLead->id, 'user_id' => $owner?->id, 'interaction_type' => 'שיחת בירור ראשונית',
            'summary' => 'מירב פנתה טלפונית, מעוניינת בתוכנית לכיתות ו׳ בלבד.', 'occurred_at' => now()->subDays(11),
        ]);
        FollowUp::create([
            'lead_id' => $yuvalimLead->id, 'user_id' => $owner?->id, 'occurred_at' => now()->subDays(2),
            'summary' => 'לחזור למירב לגבי אישור תקציב.', 'next_at' => now()->addDays(2),
        ]);

        // ----- בית ספר האלה — ליד חדש, מנוי שנתי -----
        $haela = School::create(['name' => 'בית ספר האלה', 'city' => 'הרצליה', 'phone' => '09-2223334']);
        Contact::create([
            'school_id' => $haela->id, 'name' => 'דנה לוי', 'role' => 'מנהלת',
            'phone' => '054-2223334', 'is_primary' => true, 'is_accounting_contact' => true,
        ]);
        $haelaLead = Lead::create([
            'school_id' => $haela->id, 'assigned_user_id' => $owner?->id, 'lead_source_id' => $referral?->id,
            'status_id' => $newStatus->id, 'email' => 'dana@haela.example', 'phone' => '054-2223334',
        ]);
        if ($subscriptionProgram) {
            $haelaLead->interestedPrograms()->attach($subscriptionProgram->id);
        }

        // ----- בית ספר הדקל — ממתין לשיחה חוזרת (צהוב + תת-סטטוס) -----
        $hadekel = School::create(['name' => 'בית ספר הדקל', 'city' => 'פתח תקווה', 'phone' => '03-3334445']);
        Contact::create([
            'school_id' => $hadekel->id, 'name' => 'אורית שני', 'role' => 'רכזת',
            'phone' => '050-3334445', 'is_primary' => true,
        ]);
        $hadekelLead = Lead::create([
            'school_id' => $hadekel->id, 'assigned_user_id' => $owner?->id, 'lead_source_id' => $repeatInquiry?->id,
            'status_id' => $inProcessStatus?->id ?? $newStatus->id, 'sub_status' => 'ממתינה לשיחה חוזרת',
            'email' => 'orit@hadekel.example', 'phone' => '050-3334445',
        ]);
        if ($monthlyProgram) {
            $hadekelLead->interestedPrograms()->attach($monthlyProgram->id);
        }
        FollowUp::create([
            'lead_id' => $hadekelLead->id, 'user_id' => $owner?->id, 'occurred_at' => now()->subDays(4),
            'summary' => 'לא הושגה טלפונית — ננסה שוב.', 'next_at' => now()->addDay(),
        ]);

        // ----- בית ספר רימון — נסגר ללא מכירה (אדום) -----
        $rimon = School::create(['name' => 'בית ספר רימון', 'city' => 'רמת גן']);
        Contact::create([
            'school_id' => $rimon->id, 'name' => 'מיכל אבן', 'role' => 'מזכירת ביה"ס',
            'phone' => '03-4445556', 'is_primary' => true,
        ]);
        Lead::create([
            'school_id' => $rimon->id, 'assigned_user_id' => $owner?->id, 'lead_source_id' => $landingPage?->id,
            'status_id' => $closedLostStatus?->id ?? $newStatus->id,
            'email' => 'michal@rimon.example', 'phone' => '03-4445556',
            'notes' => 'סירבו במפורש — התקציב אושר למתחרה.',
        ]);
    }
}
