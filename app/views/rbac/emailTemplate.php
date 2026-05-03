@extends('_templates.layouts.app')

@section('content')
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            {!! showPageTitle() !!}
        </h4>

        <div class="col-lg-12 order-2 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <!-- FILTER -->
                    <div class="row">
                        <div class="col-xl-12 mb-4">
                            <button type="button" class="btn btn-warning btn-sm float-end" onclick="getDataList()" title="Refresh">
                                <i class='bx bx-refresh'></i>
                            </button>
                            @can('rbac-email-create')
                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="emailTemplateForm()" title="Add New Email Template">
                                <i class='bx bx-plus'></i> Add New Email Template
                            </button>
                            @endcan
                            <select id="filter_email_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All </option>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                            </select>
                        </div>
                    </div>

                    <!-- DATATABLE -->
                    <div id="bodyDiv" class="row">
                        <div class="col-xl-12 mb-4">
                            <div id="nodataDiv" style="display: none;"> {!! nodata() !!} </div>
                            <div id="dataListDiv" class="table-responsive" style="display: block;">
                                <table id="dataList" class="table table-responsive table-hover table-striped table-bordered collapsed nowrap" width="100%">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="color:white"> Email Type </th>
                                            <th style="color:white"> Subject </th>
                                            <th style="color:white"> CC </th>
                                            <th style="color:white"> BCC </th>
                                            <th style="color:white"> Status </th>
                                            <th style="color:white"> # </th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

@endsection

@push('scripts')
    <script type="text/javascript">
        let emailTemplatesTableManager = null;

        $(document).ready(async function() {
            await getDataList();
        });

        async function getDataList(resetPaging = false) {
            const tableConfig = {
                tableId: 'dataList',
                mode: 'server',
                rowId: 'row_key',
                ajax: {
                    url: '{{ route("email-templates.list") }}',
                    method: 'POST',
                    data: function() {
                        return {
                            email_status: $("#filter_email_status").val()
                        };
                    }
                },
                columns: [
                    { data: 'type', width: '22%', targets: 0 },
                    { data: 'subject', targets: 1 },
                    { data: 'cc', width: '6%', targets: 2 },
                    { data: 'bcc', width: '6%', targets: 3 },
                    { data: 'status', width: '5%', targets: 4 },
                    {
                        data: 'action',
                        render: function(data) {
                            return data;
                        },
                        targets: -1,
                        width: '3%',
                        searchable: false,
                        orderable: false
                    }
                ],
                ui: {
                    emptyStateContainerId: 'nodataDiv',
                    loadingContainerId: 'bodyDiv',
                    showSkeleton: true,
                    useLoadingIndicator: true,
                    renderEmptyState: function() {
                        return nodata();
                    }
                },
                mutation: {
                    rowPath: null,
                    shouldKeepRow: function(rowData) {
                        const filterValue = $("#filter_email_status").val();

                        if (filterValue === '') {
                            return true;
                        }

                        return String(rowData.email_status_value) === String(filterValue);
                    }
                }
            };

            if (!emailTemplatesTableManager || !emailTemplatesTableManager.instance) {
                emailTemplatesTableManager = datatableManager('dataList', tableConfig);
                return emailTemplatesTableManager.create(tableConfig);
            }

            emailTemplatesTableManager = datatableManager('dataList', tableConfig);
            emailTemplatesTableManager.reload(resetPaging);
            return emailTemplatesTableManager.instance;
        }

        function emailTemplateForm(type = 'create', data = null) {
            modalManager().showFileContent({
                fileName: 'views/rbac/_emailTemplateForm.php',
                overlayType: 'modal',
                size: 'fullscreen',
                title: (type == 'create') ? 'REGISTER EMAIL TEMPLATE' : 'UPDATE EMAIL TEMPLATE',
                dataArray: data
            });
        }

        async function editRecord(id) {
            const res = await callApi('get', "{{ route('email-templates.show') }}".replace('{id}', id));

            if (isSuccess(res)) {
                emailTemplateForm('update', res.data.data);
            }
        }

        async function deleteRecord(id, rowKey = null) {
            await confirmDeleteAction({
                url: "{{ route('email-templates.delete') }}".replace('{id}', id),
                onSuccess: function() {
                    removeDatatableRow('dataList', rowKey);
                }
            });
        }
    </script>
@endpush