CREATE CONSTRAINT event_identity IF NOT EXISTS FOR (e:BusinessEvent) REQUIRE e.event_id IS UNIQUE;
CREATE CONSTRAINT workspace_identity IF NOT EXISTS FOR (w:Workspace) REQUIRE w.source_id IS UNIQUE;
CREATE CONSTRAINT roommate_identity IF NOT EXISTS FOR (n:RoommateNeed) REQUIRE n.source_id IS UNIQUE;
CREATE CONSTRAINT area_identity IF NOT EXISTS FOR (a:Area) REQUIRE a.name IS UNIQUE;
