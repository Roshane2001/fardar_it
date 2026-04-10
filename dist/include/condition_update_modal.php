<?php
/**
 * CONDITION UPDATE MODAL
 * Modal for manually changing customer success rate (condition)
 * File: /dist/include/condition_update_modal.php
 */
?>
<!-- Modal for Updating Customer Success Rate -->
<div class="modal" id="conditionModal" tabindex="-1" aria-labelledby="conditionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="conditionModalLabel">
                    <i class="fas fa-user-shield me-2"></i>Update Success Rate
                </h5>
                <button type="button" class="btn-close" onclick="closeConditionModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="conditionUpdateForm">
                    <input type="hidden" id="cond_order_id" name="order_id">

                    <div class="mb-4">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            Please select the new success rate for this order.
                        </div>
                    </div>

                    <div class="condition-options_container">
                        <div class="form-group mb-3">
                            <label class="form-label font-weight-bold">Select New Success Rate</label>
                            <div class="condition-options mt-2">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="condition" id="cond_excellent" value="0">
                                    <label class="form-check-label" for="cond_excellent">
                                        <span class="status-badge rate-excellent">Excellent</span>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="condition" id="cond_good" value="1">
                                    <label class="form-check-label" for="cond_good">
                                        <span class="status-badge rate-good">Good</span>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="condition" id="cond_average" value="2">
                                    <label class="form-check-label" for="cond_average">
                                        <span class="status-badge rate-average">Average</span>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="condition" id="cond_bad" value="3">
                                    <label class="form-check-label" for="cond_bad">
                                        <span class="status-badge rate-bad">Bad</span>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="condition" id="cond_new" value="4">
                                    <label class="form-check-label" for="cond_new">
                                        <span class="status-badge rate-new">New</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4">
                        <button type="button" class="btn btn-secondary me-3" onclick="closeConditionModal()">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-primary" id="saveConditionBtn" onclick="submitConditionUpdate()">
                            <i class="fas fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
