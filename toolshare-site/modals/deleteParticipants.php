<!-- Button trigger modal -->


<!-- Modal -->
<div class="modal fade" id="deleteParticipant" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-5" id="deleteParticipantLabel">Modal title</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form action="backend/participant_code.php" method="post">
        
        <p><?php echo "Are you sure you want to delete this participant?"; ?></p>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" name = "add_participant">Save changes</button>
      </div>
      </form>
    </div>
  </div>
</div>