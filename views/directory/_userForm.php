<form id="userForm" method="POST" class="mt-0">

    <div class="row">
        <div class="col-12">
            <label class="form-label"> Full Name <span class="text-danger">*</span> </label>
            <input type="text" id="name" name="name" class="form-control" onkeyup="this.value = this.value.toUpperCase();" maxlength="200" autocomplete="off" required>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Preffered Name <span class="text-danger">*</span> </label>
            <input type="text" id="user_preferred_name" name="user_preferred_name" class="form-control" autocomplete="off" maxlength="20" required>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <label class="form-label"> Email <span class="text-danger">*</span> </label>
            <input type="email" id="email" name="email" class="form-control" autocomplete="off" maxlength="255" required>
        </div>
    </div>

    <div class="row">
        <div class="col-6 mt-3">
            <label class="form-label"> Contact No <span class="text-danger">*</span> </label>
            <input type="text" id="user_contact_no" name="user_contact_no" class="form-control" autocomplete="off" maxlength="15" required>
        </div>

        <div class="col-6 mt-3">
            <label class="form-label"> Gender <span class="text-danger">*</span> </label>
            <select id="user_gender" name="user_gender" class="form-control" required>
                <option value=""> - Select - </option>
                <option value="1"> Male </option>
                <option value="2"> Female </option>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="col-6 mt-3">
            <label class="form-label"> Role / Profile <span class="text-danger">*</span> </label>
            <select id="role_id" name="role_id" class="form-control" required>
                <option value=""> - Select - </option>
            </select>
        </div>

        <div class="col-6 mt-3">
            <label class="form-label"> Status <span class="text-danger">*</span> </label>
            <select id="user_status" name="user_status" class="form-control" required>
                <option value=""> - Select - </option>
                <option value="0"> Inactive </option>
                <option value="1"> Active </option>
                <option value="2"> Banned/Suspended </option>
            </select>
        </div>
    </div>

    <div class="row" id="passwordDiv" style="display: none;">
        <div class="col-6 mt-3">
            <label class="form-label"> Username <span class="text-danger">*</span> </label>
            <input type="text" id="username" name="username" class="form-control" autocomplete="off" maxlength="15" required>
        </div>
        <div class="col-6 mt-3">
            <label class="form-label"> password <span class="text-danger">*</span> </label>
            <input type="password" id="password" name="password" class="form-control" autocomplete="off" maxlength="15" required>
        </div>
    </div>

    <div class="row mt-2">
        <div class="col-lg-12">
            <span class="text-danger">* Indicates a required field</span>
            <input type="hidden" id="id" name="id" placeholder="id">
        </div>
    </div>

    <input type="hidden" name="action" value="save" readonly>
    <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3" style="position: absolute;bottom: 0;"> <i class='bx bx-save'></i> Save </button>
</form>

<script>
    async function getPassData(baseUrl, data) {
        // console.log('form : ', data);
        await getProfileList('role_id', false);

        if (empty(data)) {
            $('#passwordDiv').show();
        } else {
            $('#role_id').val(data.profile.role_id ?? 2);
            $('#passwordDiv').hide();
        }
    }

    $("#userForm").submit(function(event) {
        event.preventDefault();

        if (validateDataUser()) {

            const form = $(this);
            const url = form.attr('action');

            Swal.fire({
                title: 'Are you sure?',
                html: "Form will be submitted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Confirm!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(
                async (result) => {
                    if (result.isConfirmed) {
                        const res = await submitApi(url, form.serializeArray(), 'userForm');
                        if (isSuccess(res)) {

                            if (isSuccess(res.data.code)) {
                                noti(res.status, res.data.message);
                                getDataList();
                            } else {
                                noti(400, res.data.message)
                            }

                        }
                    }
                })

        } else {
            validationJsError('toastr', 'single'); // single or multi
        }
    });

    function validateDataUser() {

        const rules = {
            'name': 'required|min:3|max:255',
            'user_preferred_name': 'required|min:3|max:255',
            'email': 'required|email|max_length:255',
            'user_contact_no': 'required|integer|min_length:10|max_length:15',
            'role_id': 'required|integer|min:1',
            'user_status': 'required|integer|min:1',
            'id': 'integer',
        };

        const message = {
            'name': 'Full Name',
            'user_preferred_name': 'Preffered Rank',
            'email': 'Email',
            'user_contact_no': 'Contact No',
            'role_id': 'Role',
            'user_status': 'Status',
            'id': 'ID',
        };

        return validationJs(rules, message);
    }
</script>