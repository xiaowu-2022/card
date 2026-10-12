import { AdminPagination } from '@/components/admin/AdminPagination';
import { adminPageHref } from '@/lib/admin-pagination';
export type ListPagination = {
    previous: string | null;
    next: string | null;
    total: number;
    currentPage: number;
    lastPage: number;
    first?: string;
};
export function AdminPageLinks({ page }: { page?: ListPagination }) {
    if (!page) return null;
    return (
        <AdminPagination
            className="border-t p-4"
            page={page.currentPage}
            pages={page.lastPage}
            total={page.total}
            href={(target) =>
                adminPageHref(
                    {
                        first_page_url: page.first,
                        prev_page_url: page.previous,
                        next_page_url: page.next,
                    },
                    target,
                )
            }
        />
    );
}
