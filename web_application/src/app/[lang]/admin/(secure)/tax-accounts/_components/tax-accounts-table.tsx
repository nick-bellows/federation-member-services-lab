'use client';

import { DataTable } from '@/app/components/Table/DataTable';
import HeaderSort from '@/app/components/Table/HeaderSort';
import { ResourceName } from '@/resources/resource';
import { TTaxAccountDeserialized } from '@/types/resources';
import { createDeleteFormAction } from '@/utils/deleteActions';
import { listTaxAccountSearchParams } from '@/utils/search-params';
import { ColumnDef } from '@tanstack/react-table';
import useTranslation from 'next-translate/useTranslation';
import CreateButton from '../../components/CreateButton';
import BelongsToCell from '@/app/components/Table/BelongsToCell';

interface Props {
    taxAccounts: TTaxAccountDeserialized[];
    totalPages: number;
}

export default function TaxAccountsTable({ taxAccounts, totalPages }: Props) {
    const { t } = useTranslation();
    const deleteAction = createDeleteFormAction('tax-accounts');

    const columns: ColumnDef<TTaxAccountDeserialized>[] = [
        {
            header: ({ column }) => (
                <HeaderSort
                    parser={listTaxAccountSearchParams.sort}
                    columnId={column.id}
                    columnTitle={t('tax_account:account_number.label')}
                />
            ),
            accessorKey: 'accountNumber',
            cell: ({ row }) => {
                const taxAccount = row.original;

                return (
                    <BelongsToCell
                        resource={taxAccount}
                        path="/admin/tax-accounts"
                        content={taxAccount.accountNumber}
                        truncate
                    />
                );
            },
        },
        {
            header: ({ column }) => (
                <HeaderSort
                    parser={listTaxAccountSearchParams.sort}
                    columnId={column.id}
                    columnTitle={t('tax_account:description.label')}
                />
            ),
            accessorKey: 'description',
        },
    ];

    return (
        <>
            <div className="flex justify-end">
                <CreateButton href="/admin/tax-accounts/create" />
            </div>
            <DataTable
                data={taxAccounts}
                columns={columns}
                resourceName={'tax-accounts' as ResourceName}
                totalPages={totalPages}
                canEdit={true}
                canView={true}
                deleteAction={deleteAction}
            />
        </>
    );
}
