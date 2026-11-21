<?php

namespace App\Platform\Access;

/**
 * Requirement 3.a — the CRUD vs CRU action level a role carries.
 *
 * A role is not just "which menu/module you may open" but "what you may DO
 * there". This class encodes the two levels the Principal assigns:
 *
 *  - {@see self::CRU}  — View / Create / Update only. The actor can never
 *    remove a record (no destructive action).
 *  - {@see self::CRUD} — View / Create / Update / Delete. Only this level may
 *    perform a destructive action.
 *
 * The level is a pure function of the role key (see
 * {@see RbacCatalog::roleActionScopes()}) and is materialised onto
 * `roles.action_scope`, so when a role is attached to a user the level applies
 * automatically — no separate, per-user toggle exists to drift from.
 *
 * This deliberately does NOT introduce a new permission system: it narrows the
 * EXISTING permissions. A role that already lacks a capability still cannot
 * exercise it; the action scope only ever REMOVES the destructive part for a
 * CRU role.
 */
final class ActionScope
{
    public const CRU = 'cru';

    public const CRUD = 'crud';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CRU, self::CRUD];
    }

    /**
     * The concrete verbs a scope carries.
     *
     * @return list<string>
     */
    public static function actions(string $scope): array
    {
        $base = ['view', 'create', 'update'];

        return self::allowsDelete($scope) ? [...$base, 'delete'] : $base;
    }

    public static function allowsDelete(string $scope): bool
    {
        return $scope === self::CRUD;
    }

    /**
     * Only the two canonical values are accepted; anything else falls back to
     * the safer CRU level (fail-closed — never grant delete by omission).
     */
    public static function normalize(?string $scope): string
    {
        return in_array($scope, self::all(), true) ? (string) $scope : self::CRU;
    }

    public static function label(?string $scope): string
    {
        return __("lba.action_scope.".self::normalize($scope));
    }
}
