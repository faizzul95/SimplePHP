<div id="listPermissionAssignmentTable"></div>
<input id='tempPermRoleID' type='hidden' readonly>
<input id='tempPermRoleName' type='hidden' readonly>

<script>
    async function getPassData(baseUrl, data) {
        $('#listPermissionAssignmentTable').html(nodata());
        $('#tempPermRoleID').val(data.id);
        $('#tempPermRoleName').val(data.name);
        await getListPermissionAssignment();
    }

    async function getListPermissionAssignment() {
        const res = await callApi('post', "{{ route('permissions.list-assignment') }}", {
            'id': $('#tempPermRoleID').val()
        });

        if (!empty(res.data)) {

            // Generate responsive table HTML with Bootstrap classes
            let table = `<div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th scope="col" class="text-white">Grant</th>
                            <th scope="col" class="text-white">Name</th>
                            <th scope="col" class="text-white">Slug</th>
                            <th scope="col" class="text-white">Description</th>
                        </tr>
                    </thead>
                    <tbody>`;

            res.data.forEach(item => {
                table += `<tr>
                    <td><center>${item.checkbox}</center></td>
                    <td>${item.abilities_name}</td>
                    <td>${item.abilities_slug}</td>
                    <td>${item.abilities_desc}</td>
                </tr>`;
            });

            table += `</tbody></table></div>`;
            $('#listPermissionAssignmentTable').empty();
            $('#listPermissionAssignmentTable').html(table);
        } else {
            $('#listPermissionAssignmentTable').html(nodata());
        }
    }

    async function grantPermission(roleID, abilitiesID, isAllAccess = false) {

        let isChecked = $('#ab' + abilitiesID).prop('checked');

        if (isAllAccess) {
            if (!isChecked) {
                $('.list-grant-perm').removeAttr('disabled');
            } else {
                $('.list-grant-perm').attr('disabled', true);
            }
            $('.list-grant-perm').prop('checked', isChecked);
        }

        let role = $('#tempPermRoleName').val();
        let actionText = isChecked ? 'grant' : 'revoke';
        let actionDesc = isChecked ?
            'This will <b>grant</b> access to <b>' + role + '</b> for the selected permission.' :
            'This will <b>revoke</b> access from <b>' + role + '</b> for the selected permission.';
        let confirmBtnText = isChecked ? 'Yes, Grant Access!' : 'Yes, Revoke Access!';

        await confirmApiAction({
            title: isChecked ? 'Grant Permission?' : 'Revoke Permission?',
            html: actionDesc,
            confirmButtonColor: isChecked ? '#198754' : '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmBtnText,
            method: 'post',
            url: "{{ route('permissions.save-assignment') }}",
            data: {
                role_id: roleID,
                abilities_id: abilitiesID,
                all_access: isAllAccess,
                permission: actionText,
            },
            onSuccess: async function() {
                getListPermissionAssignment();
            },
            onCancel: async function() {
                // Reset checkbox to its original state if cancelled
                $('#ab' + abilitiesID).prop('checked', !isChecked);
                // If disabled, enable it back
                if ($('#ab' + abilitiesID).is(':disabled')) {
                    $('#ab' + abilitiesID).prop('disabled', false);
                }
                if (isAllAccess) {
                    $('.list-grant-perm').prop('checked', !isChecked);
                    // If user tried to uncheck all access but cancelled, restore disabled state
                    if (!isChecked) {
                        $('.list-grant-perm').attr('disabled', true);
                    } else {
                        $('.list-grant-perm').prop('disabled', false);
                    }
                }
            }
        });

    }
</script>