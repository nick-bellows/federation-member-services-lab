import 'server-only';

import type { FederationClient } from './client';
import {
    findIncluded,
    relatedId,
    relatedIds,
    type ApplicationResource,
    type DocumentResource,
    type JsonApiResource,
    type OrganizationAttributes,
    type SeasonAttributes,
    type UserAttributes,
    type WindowResource,
} from './types';

/**
 * Federation (fork): read helpers for the pages. Each returns a flattened
 * view of a compound JSON:API document. The API scopes every list to what
 * the signed-in user may see; nothing here filters by identity.
 */
type Compound<T> = {
    data: T;
    included?: JsonApiResource<Record<string, unknown>>[];
};

export interface WindowView {
    id: string;
    opensAt: string;
    closesAt: string;
    roles: WindowResource['attributes']['roles'];
    isOpen: boolean;
    organization: { id: string; name: string; code: string } | null;
    season: { id: string; label: string } | null;
}

export async function listOpenWindows(
    client: FederationClient,
): Promise<WindowView[]> {
    const { data, error } = await client.GET('/registration-windows', {
        params: {
            query: {
                'filter[open]': 'true',
                include: 'memberOrganization,season',
                sort: '-opensAt',
            } as never,
        },
    });

    if (error || !data) {
        return [];
    }

    const document = data as unknown as Compound<WindowResource[]>;

    return document.data.map((window) =>
        toWindowView(window, document.included),
    );
}

export async function listWindows(
    client: FederationClient,
): Promise<WindowView[]> {
    const { data, error } = await client.GET('/registration-windows', {
        params: {
            query: {
                include: 'memberOrganization,season',
                sort: '-opensAt',
            } as never,
        },
    });

    if (error || !data) {
        return [];
    }

    const document = data as unknown as Compound<WindowResource[]>;

    return document.data.map((window) =>
        toWindowView(window, document.included),
    );
}

function toWindowView(
    window: WindowResource,
    included: Compound<unknown>['included'],
): WindowView {
    const organization = findIncluded<OrganizationAttributes>(
        included,
        'member-organizations',
        relatedId(window, 'memberOrganization'),
    );
    const season = findIncluded<SeasonAttributes>(
        included,
        'seasons',
        relatedId(window, 'season'),
    );

    return {
        id: window.id,
        opensAt: window.attributes.opensAt,
        closesAt: window.attributes.closesAt,
        roles: window.attributes.roles,
        isOpen: window.attributes.isOpen === 'true',
        organization: organization
            ? {
                  id: organization.id,
                  name: organization.attributes.name,
                  code: organization.attributes.code,
              }
            : null,
        season: season
            ? { id: season.id, label: season.attributes.label }
            : null,
    };
}

export interface ApplicationView {
    id: string;
    attributes: ApplicationResource['attributes'];
    organization: { id: string; name: string; code: string } | null;
    season: { id: string; label: string } | null;
    window: { id: string; closesAt: string } | null;
    applicant: { id: string; name: string; email: string } | null;
    documents: DocumentResource[];
}

const applicationInclude =
    'documents,memberOrganization,season,registrationWindow,applicant';

export async function listApplications(
    client: FederationClient,
    statuses?: string[],
): Promise<ApplicationView[]> {
    const query: Record<string, string> = {
        include: applicationInclude,
        sort: '-createdAt',
    };

    if (statuses && statuses.length > 0) {
        query['filter[status]'] = statuses.join(',');
    }

    const { data, error } = await client.GET('/registration-applications', {
        params: { query: query as never },
    });

    if (error || !data) {
        return [];
    }

    const document = data as unknown as Compound<ApplicationResource[]>;

    return document.data.map((application) =>
        toApplicationView(application, document.included),
    );
}

export async function getApplication(
    client: FederationClient,
    id: string,
): Promise<{ view: ApplicationView | null; status: number }> {
    const { data, error, response } = await client.GET(
        '/registration-applications/{registration_application}',
        {
            params: {
                path: { registration_application: id },
                query: { include: applicationInclude } as never,
            },
        },
    );

    if (error || !data) {
        return { view: null, status: response?.status ?? 500 };
    }

    const document = data as unknown as Compound<ApplicationResource>;

    return {
        view: toApplicationView(document.data, document.included),
        status: 200,
    };
}

function toApplicationView(
    application: ApplicationResource,
    included: Compound<unknown>['included'],
): ApplicationView {
    const organization = findIncluded<OrganizationAttributes>(
        included,
        'member-organizations',
        relatedId(application, 'memberOrganization'),
    );
    const season = findIncluded<SeasonAttributes>(
        included,
        'seasons',
        relatedId(application, 'season'),
    );
    const window = findIncluded<WindowResource['attributes']>(
        included,
        'registration-windows',
        relatedId(application, 'registrationWindow'),
    );
    const applicant = findIncluded<UserAttributes>(
        included,
        'federation-users',
        relatedId(application, 'applicant'),
    );
    const documents = relatedIds(application, 'documents')
        .map((documentId) =>
            findIncluded<DocumentResource['attributes']>(
                included,
                'application-documents',
                documentId,
            ),
        )
        .filter((d): d is DocumentResource => d !== undefined);

    return {
        id: application.id,
        attributes: application.attributes,
        organization: organization
            ? {
                  id: organization.id,
                  name: organization.attributes.name,
                  code: organization.attributes.code,
              }
            : null,
        season: season
            ? { id: season.id, label: season.attributes.label }
            : null,
        window: window
            ? { id: window.id, closesAt: window.attributes.closesAt }
            : null,
        applicant: applicant
            ? {
                  id: applicant.id,
                  name: applicant.attributes.name,
                  email: applicant.attributes.email,
              }
            : null,
        documents,
    };
}

export interface ReferenceData {
    organizations: { id: string; name: string; code: string }[];
    seasons: { id: string; label: string }[];
}

export async function listReferenceData(
    client: FederationClient,
): Promise<ReferenceData> {
    const [organizations, seasons] = await Promise.all([
        client.GET('/member-organizations', {
            params: { query: { sort: 'code' } as never },
        }),
        client.GET('/seasons', {
            params: { query: { sort: '-label' } as never },
        }),
    ]);

    const orgs =
        (
            organizations.data as unknown as
                Compound<JsonApiResource<OrganizationAttributes>[]> | undefined
        )?.data ?? [];
    const seas =
        (
            seasons.data as unknown as
                Compound<JsonApiResource<SeasonAttributes>[]> | undefined
        )?.data ?? [];

    return {
        organizations: orgs.map((o) => ({
            id: o.id,
            name: o.attributes.name,
            code: o.attributes.code,
        })),
        seasons: seas.map((s) => ({ id: s.id, label: s.attributes.label })),
    };
}
