# GHL Config — Administrator Guide

The plugin bootstrap in `includes/class-ghl-plugin.php` registers the **GHL Config** admin page. Only WordPress administrators (users with `manage_options`) can access it.

## Initial setup

1. In WordPress, go to **GHL Config**.
2. Enter the **Private Integration Token** and **Location ID**.
3. Click **Refresh from GHL** to load users, pipelines, and calendars.
4. Select or enter the **Active Pipeline**, then click **Refresh from GHL** again to load its stages.
5. Complete the routing settings and click **Save Configuration**.

## Connection

**Purpose:** Connect WordPress to the correct GoHighLevel location and define the main integration values.

| Setting | How to configure it |
| --- | --- |
| Private Integration Token | Paste the GHL private integration token. A leading `Bearer` and spaces are removed when saved. |
| Location ID | Enter the GHL sub-account/location ID. |
| Active Pipeline | Select a loaded pipeline or enter its pipeline ID. Refresh again after changing it. |
| Message Custom Field ID | Enter the GHL contact custom-field ID that should store the submitted message. Leave blank if unused. |
| Redirect URL | Enter the page URL shown after a successful form submission. The plugin adds `contact_id` and `opportunity_id` to the URL. Leave blank to disable the redirect. |

## Routing Defaults

**Purpose:** Provide fallback routing when no specific state or user mapping applies.

- **Default User:** Receives the lead when its sales state has no assigned user.
- **Default Pipeline Stage:** Receives the opportunity when the selected user has no assigned stage.

## Sales State Routing

**Purpose:** Route leads to a GHL user according to the submitted U.S. state.

Choose an **Assigned User** for each state you serve. Unmapped or unrecognized states use the **Default User**.

## Users

**Purpose:** Define the stage and booking calendar used for each GHL user.

- **Assigned Pipeline Stage:** Stage used when that user receives a lead.
- **Assigned Calendar:** Calendar used for that user's booking page.

The default user's calendar is also the fallback calendar. User IDs are displayed for reference.

## Sales Stages

**Purpose:** Show the stages loaded from the active pipeline.

This section is read-only. If it is empty, set the **Active Pipeline** and click **Refresh from GHL**.

## Calendars

**Purpose:** Show the active GHL calendars available for user assignments.

This section is read-only. Calendar name, ID, and type are displayed.

## Save and refresh

- **Save Configuration:** Saves the current fields and mappings without contacting GHL.
- **Refresh from GHL:** Saves first, then reloads users, pipelines, stages, and active calendars. Existing data is kept when a refresh request fails.
- **Last GHL refresh:** Shows the last fully successful refresh time.

Refresh after changing users, pipelines, stages, or calendars in GHL, then review and save the mappings again.
