@agent001_assigning_agents
Feature: Assigning Agent001 agents to sales channels
    In order to give each sales channel its own assistant
    As an Administrator
    I want to assign an Agent001 agent to a sales channel

    Background:
        Given the store operates on a single channel in "United States"
        And the store is connected with Agent001
        And the Agent001 account has an agent "Support"
        And I am logged in as an administrator

    @ui
    Scenario: Assigning an agent queues the first catalog synchronization
        When I open the Agent001 assistant page
        And I assign the agent "Support" to the "United States" channel
        And I save the agents
        Then I should be notified that the agents have been saved
        And the "United States" channel should use the agent "Support"
        And the catalog synchronization of the "United States" channel should be queued
        And the getting started guide should point to "Synchronize catalog"
